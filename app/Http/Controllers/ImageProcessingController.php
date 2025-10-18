<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageProcessingController extends Controller
{
    private $huggingFaceApiKey;
    private $huggingFaceBaseUrl;

    public function __construct()
    {
        $this->huggingFaceApiKey = config('services.huggingface.api_key');
        $this->huggingFaceBaseUrl = config('services.huggingface.base_url', 'https://api-inference.huggingface.co/models');
    }

    /**
     * Extract text from image using Hugging Face OCR
     */
    public function extractText(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'image' => 'required|image|max:10240', // 10MB max
            ]);

            $image = $request->file('image');
            $imageData = base64_encode(file_get_contents($image->getPathname()));

            // Use Microsoft's TrOCR model for text recognition
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->huggingFaceApiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->huggingFaceBaseUrl . '/microsoft/trocr-base-printed', [
                'inputs' => 'data:image/jpeg;base64,' . $imageData,
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $extractedText = is_array($result) && isset($result[0]['generated_text']) 
                    ? $result[0]['generated_text'] 
                    : '';

                return response()->json([
                    'success' => true,
                    'extracted_text' => $extractedText,
                    'confidence' => 0.95, // TrOCR doesn't provide confidence, using high default
                ]);
            } else {
                Log::error('Hugging Face OCR API Error', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to extract text from image',
                    'error' => 'OCR service unavailable'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Image text extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process image',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Translate text using Hugging Face translation models
     */
    public function translateText(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'text' => 'required|string|max:5000',
                'source_language' => 'nullable|string|max:10',
                'target_language' => 'required|string|max:10',
            ]);

            $text = $request->input('text');
            $sourceLanguage = $request->input('source_language', 'auto');
            $targetLanguage = $request->input('target_language');

            // Use Helsinki-NLP translation models
            $model = $this->getTranslationModel($sourceLanguage, $targetLanguage);
            
            if (!$model) {
                return response()->json([
                    'success' => false,
                    'message' => 'Translation not supported for this language pair',
                    'supported_languages' => $this->getSupportedLanguages()
                ], 400);
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->huggingFaceApiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->huggingFaceBaseUrl . '/' . $model, [
                'inputs' => $text,
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $translatedText = is_array($result) && isset($result[0]['translation_text']) 
                    ? $result[0]['translation_text'] 
                    : $text;

                return response()->json([
                    'success' => true,
                    'original_text' => $text,
                    'translated_text' => $translatedText,
                    'source_language' => $sourceLanguage,
                    'target_language' => $targetLanguage,
                ]);
            } else {
                Log::error('Hugging Face Translation API Error', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Translation failed',
                    'error' => 'Translation service unavailable'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Text translation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to translate text',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Identify language of text using Hugging Face language detection
     */
    public function identifyLanguage(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'text' => 'required|string|max:1000',
            ]);

            $text = $request->input('text');

            // Use Facebook's language detection model
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->huggingFaceApiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->huggingFaceBaseUrl . '/facebook/fasttext-language-identification', [
                'inputs' => $text,
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $languageCode = is_array($result) && isset($result[0]['label']) 
                    ? str_replace('__label__', '', $result[0]['label'])
                    : 'en';

                return response()->json([
                    'success' => true,
                    'language_code' => $languageCode,
                    'language_name' => $this->getLanguageName($languageCode),
                    'confidence' => is_array($result) && isset($result[0]['score']) ? $result[0]['score'] : 0.9,
                ]);
            } else {
                // Fallback to simple language detection
                $detectedLanguage = $this->simpleLanguageDetection($text);
                
                return response()->json([
                    'success' => true,
                    'language_code' => $detectedLanguage,
                    'language_name' => $this->getLanguageName($detectedLanguage),
                    'confidence' => 0.7,
                    'note' => 'Using fallback detection'
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Language identification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Fallback to simple detection
            $detectedLanguage = $this->simpleLanguageDetection($request->input('text'));
            
            return response()->json([
                'success' => true,
                'language_code' => $detectedLanguage,
                'language_name' => $this->getLanguageName($detectedLanguage),
                'confidence' => 0.5,
                'note' => 'Using fallback detection due to service error'
            ]);
        }
    }

    /**
     * Process image with text extraction, language detection, and translation
     */
    public function processImageWithTranslation(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'image' => 'required|image|max:10240',
                'target_language' => 'required|string|max:10',
            ]);

            $image = $request->file('image');
            $targetLanguage = $request->input('target_language');

            // Step 1: Extract text from image
            $textResponse = $this->extractText($request);
            $textData = $textResponse->getData(true);

            if (!$textData['success'] || empty($textData['extracted_text'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'No text found in the image',
                    'extracted_text' => '',
                    'source_language' => '',
                    'translated_text' => '',
                ]);
            }

            $extractedText = $textData['extracted_text'];

            // Step 2: Identify source language
            $languageRequest = new Request(['text' => $extractedText]);
            $languageResponse = $this->identifyLanguage($languageRequest);
            $languageData = $languageResponse->getData(true);
            $sourceLanguage = $languageData['language_code'] ?? 'en';

            // Step 3: Translate if needed
            $translatedText = $extractedText;
            if ($sourceLanguage !== $targetLanguage) {
                $translateRequest = new Request([
                    'text' => $extractedText,
                    'source_language' => $sourceLanguage,
                    'target_language' => $targetLanguage,
                ]);
                $translateResponse = $this->translateText($translateRequest);
                $translateData = $translateResponse->getData(true);
                
                if ($translateData['success']) {
                    $translatedText = $translateData['translated_text'];
                }
            }

            return response()->json([
                'success' => true,
                'extracted_text' => $extractedText,
                'source_language' => $sourceLanguage,
                'source_language_name' => $this->getLanguageName($sourceLanguage),
                'translated_text' => $translatedText,
                'target_language' => $targetLanguage,
                'target_language_name' => $this->getLanguageName($targetLanguage),
            ]);

        } catch (\Exception $e) {
            Log::error('Image processing with translation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process image with translation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get supported languages for translation
     */
    public function getSupportedLanguages(): JsonResponse
    {
        $languages = [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'zh' => 'Chinese',
            'ar' => 'Arabic',
            'hi' => 'Hindi',
            'th' => 'Thai',
            'vi' => 'Vietnamese',
            'tl' => 'Filipino',
            'id' => 'Indonesian',
            'ms' => 'Malay',
            'tr' => 'Turkish',
            'pl' => 'Polish',
            'nl' => 'Dutch',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'no' => 'Norwegian',
            'fi' => 'Finnish',
            'cs' => 'Czech',
            'sk' => 'Slovak',
            'hu' => 'Hungarian',
            'ro' => 'Romanian',
            'bg' => 'Bulgarian',
            'hr' => 'Croatian',
            'sl' => 'Slovenian',
            'et' => 'Estonian',
            'lv' => 'Latvian',
            'lt' => 'Lithuanian',
            'el' => 'Greek',
            'he' => 'Hebrew',
            'fa' => 'Persian',
            'ur' => 'Urdu',
            'bn' => 'Bengali',
            'ta' => 'Tamil',
            'te' => 'Telugu',
            'ml' => 'Malayalam',
            'kn' => 'Kannada',
            'gu' => 'Gujarati',
            'pa' => 'Punjabi',
            'mr' => 'Marathi',
            'ne' => 'Nepali',
            'si' => 'Sinhala',
            'my' => 'Myanmar',
            'km' => 'Khmer',
            'lo' => 'Lao',
            'ka' => 'Georgian',
            'hy' => 'Armenian',
            'az' => 'Azerbaijani',
            'kk' => 'Kazakh',
            'ky' => 'Kyrgyz',
            'uz' => 'Uzbek',
            'tg' => 'Tajik',
            'mn' => 'Mongolian',
            'bo' => 'Tibetan',
            'dz' => 'Dzongkha',
        ];

        return response()->json([
            'success' => true,
            'languages' => $languages,
        ]);
    }

    /**
     * Get translation model for language pair
     */
    private function getTranslationModel(string $sourceLanguage, string $targetLanguage): ?string
    {
        $models = [
            'en-es' => 'Helsinki-NLP/opus-mt-en-es',
            'es-en' => 'Helsinki-NLP/opus-mt-es-en',
            'en-fr' => 'Helsinki-NLP/opus-mt-en-fr',
            'fr-en' => 'Helsinki-NLP/opus-mt-fr-en',
            'en-de' => 'Helsinki-NLP/opus-mt-en-de',
            'de-en' => 'Helsinki-NLP/opus-mt-de-en',
            'en-it' => 'Helsinki-NLP/opus-mt-en-it',
            'it-en' => 'Helsinki-NLP/opus-mt-it-en',
            'en-pt' => 'Helsinki-NLP/opus-mt-en-pt',
            'pt-en' => 'Helsinki-NLP/opus-mt-pt-en',
            'en-ru' => 'Helsinki-NLP/opus-mt-en-ru',
            'ru-en' => 'Helsinki-NLP/opus-mt-ru-en',
            'en-ja' => 'Helsinki-NLP/opus-mt-en-jap',
            'ja-en' => 'Helsinki-NLP/opus-mt-jap-en',
            'en-ko' => 'Helsinki-NLP/opus-mt-en-ko',
            'ko-en' => 'Helsinki-NLP/opus-mt-ko-en',
            'en-zh' => 'Helsinki-NLP/opus-mt-en-zh',
            'zh-en' => 'Helsinki-NLP/opus-mt-zh-en',
            'en-ar' => 'Helsinki-NLP/opus-mt-en-ar',
            'ar-en' => 'Helsinki-NLP/opus-mt-ar-en',
            'en-hi' => 'Helsinki-NLP/opus-mt-en-hi',
            'hi-en' => 'Helsinki-NLP/opus-mt-hi-en',
            'en-th' => 'Helsinki-NLP/opus-mt-en-th',
            'th-en' => 'Helsinki-NLP/opus-mt-th-en',
            'en-vi' => 'Helsinki-NLP/opus-mt-en-vi',
            'vi-en' => 'Helsinki-NLP/opus-mt-vi-en',
            'en-tl' => 'Helsinki-NLP/opus-mt-en-tl',
            'tl-en' => 'Helsinki-NLP/opus-mt-tl-en',
        ];

        $key = $sourceLanguage . '-' . $targetLanguage;
        return $models[$key] ?? null;
    }

    /**
     * Simple language detection fallback
     */
    private function simpleLanguageDetection(string $text): string
    {
        // Simple regex-based language detection
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) {
            return 'zh'; // Chinese
        }
        if (preg_match('/[\x{3040}-\x{309f}\x{30a0}-\x{30ff}]/u', $text)) {
            return 'ja'; // Japanese
        }
        if (preg_match('/[\x{ac00}-\x{d7af}]/u', $text)) {
            return 'ko'; // Korean
        }
        if (preg_match('/[\x{0600}-\x{06ff}]/u', $text)) {
            return 'ar'; // Arabic
        }
        if (preg_match('/[\x{0900}-\x{097f}]/u', $text)) {
            return 'hi'; // Hindi
        }
        if (preg_match('/[\x{0e00}-\x{0e7f}]/u', $text)) {
            return 'th'; // Thai
        }
        if (preg_match('/[\x{1e00}-\x{1eff}]/u', $text)) {
            return 'vi'; // Vietnamese
        }
        
        // Default to English
        return 'en';
    }

    /**
     * Get language name from code
     */
    private function getLanguageName(string $languageCode): string
    {
        $languageNames = [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'zh' => 'Chinese',
            'ar' => 'Arabic',
            'hi' => 'Hindi',
            'th' => 'Thai',
            'vi' => 'Vietnamese',
            'tl' => 'Filipino',
            'id' => 'Indonesian',
            'ms' => 'Malay',
            'tr' => 'Turkish',
            'pl' => 'Polish',
            'nl' => 'Dutch',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'no' => 'Norwegian',
            'fi' => 'Finnish',
            'cs' => 'Czech',
            'sk' => 'Slovak',
            'hu' => 'Hungarian',
            'ro' => 'Romanian',
            'bg' => 'Bulgarian',
            'hr' => 'Croatian',
            'sl' => 'Slovenian',
            'et' => 'Estonian',
            'lv' => 'Latvian',
            'lt' => 'Lithuanian',
            'el' => 'Greek',
            'he' => 'Hebrew',
            'fa' => 'Persian',
            'ur' => 'Urdu',
            'bn' => 'Bengali',
            'ta' => 'Tamil',
            'te' => 'Telugu',
            'ml' => 'Malayalam',
            'kn' => 'Kannada',
            'gu' => 'Gujarati',
            'pa' => 'Punjabi',
            'mr' => 'Marathi',
            'ne' => 'Nepali',
            'si' => 'Sinhala',
            'my' => 'Myanmar',
            'km' => 'Khmer',
            'lo' => 'Lao',
            'ka' => 'Georgian',
            'hy' => 'Armenian',
            'az' => 'Azerbaijani',
            'kk' => 'Kazakh',
            'ky' => 'Kyrgyz',
            'uz' => 'Uzbek',
            'tg' => 'Tajik',
            'mn' => 'Mongolian',
            'bo' => 'Tibetan',
            'dz' => 'Dzongkha',
        ];

        return $languageNames[$languageCode] ?? strtoupper($languageCode);
    }
}
