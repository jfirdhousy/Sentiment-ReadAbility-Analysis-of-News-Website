<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class SentimentAnalysisController extends Controller
{
    // Health check endpoint
    public function health()
    {
        return response()->json([
            'status' => 'ok',
            'message' => 'Laravel API is running',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    // Fungsi utama API
    public function analyze(Request $request)
    {
        // Health check dengan parameter khusus
        if ($request->input('text') === 'health-check') {
            return response()->json([
                'status' => 'ok',
                'message' => 'Laravel API is running',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        // Handle file upload atau text input
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $text = $this->extractTextFromFile($file);

            Log::channel('user_activity')->info('User uploaded a file', [
                'filename' => $file->getClientOriginalName(),
                'filesize' => $file->getSize(),
                'ip' => $request->ip(),
                'time' => now()->toDateTimeString(),
            ]);

        } else {
            $text = $request->input('text');

            Log::channel('user_activity')->info('User submitted text input', [
                'length' => strlen($text ?? ''),
                'ip' => $request->ip(),
                'time' => now()->toDateTimeString(),
            ]);
        }

        if (!$text) {
            return response()->json([
                'success' => false,
                'error' => 'Text or file is required'
            ], 400);
        }

        // Analisis Sentimen (Gemini API)
        $sentimentResult = $this->analyzeSentiment($text);

        // Analisis Keterbacaan (Flesch Reading Ease)
        $readabilityResult = $this->analyzeReadability($text);

        // Match sample response: truncate text to 500 chars with ellipsis
        $displayText = strlen($text) > 500 ? substr($text, 0, 500).'...' : $text;

        // return response()->json([
        //     'success' => true,
        //     'text' => $displayText,
        //     'sentiment' => $sentimentResult['sentiment'],
        //     'sentiment_score' => $sentimentResult['score'],
        //     'sentiment_details' => $sentimentResult['details'],
        //     'readability' => $readability,
        //     'readability_category' => $this->getReadabilityCategory($readability),
        //     'word_count' => str_word_count($text),
        //     'sentence_count' => count(preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY))
        // ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return response()->json([
            'success' => true, // Opsional, tapi baik untuk debugging
            'text' => $displayText,

            // Data Sentimen
            'sentiment' => $sentimentResult['sentiment'],
            'sentiment_score' => $sentimentResult['score'],
            'sentiment_details' => $sentimentResult['details'],

            // Data Keterbacaan
            'readability' => $readabilityResult['score'],
            'readability_category' => $this->getReadabilityCategory($readabilityResult['score']),
            'word_count' => $readabilityResult['word_count'],
            'sentence_count' => $readabilityResult['sentence_count'],
            
            // Statistik Detail Flesch (Sesuai FleschStatistics di api.ts)
            'statistics' => [
                'syllable_count' => $readabilityResult['syllable_count'],
                'avg_word_length' => $readabilityResult['avg_word_length'],
                'avg_sentence_length' => $readabilityResult['avg_sentence_length']
            ],

            // Data Tabel (Sesuai EntityThemeData[] di api.ts)
            'entitas_terdeteksi' => $sentimentResult['entitas'] ?? [],
            'tema_terdeteksi' => $sentimentResult['tema'] ?? [],
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    }

    private function extractTextFromFile($file)
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $filePath = $file->getRealPath();

        if ($extension === 'txt') {
            return file_get_contents($filePath);
        } elseif ($extension === 'pdf') {
            // Extract text from PDF using pdftotext
            if (shell_exec('which pdftotext')) {
                $output = shell_exec("pdftotext '$filePath' -");
                if ($output && strlen(trim($output)) > 0) {
                    return trim($output);
                }
            }
            throw new \Exception('Unable to extract text from PDF. Please try converting to .txt format first.');
        } elseif ($extension === 'docx') {
            // Extract text from DOCX using ZipArchive
            if (!class_exists('ZipArchive')) {
                throw new \Exception('ZipArchive class not available for DOCX processing.');
            }
            
            $zip = new \ZipArchive;
            if ($zip->open($filePath) !== true) {
                throw new \Exception('Unable to open DOCX file.');
            }
            
            $xmlContent = $zip->getFromName('word/document.xml');
            $zip->close();
            
            if ($xmlContent === false) {
                throw new \Exception('Unable to extract content from DOCX file.');
            }
            
            // Parse XML to extract text
            try {
                $dom = new \DOMDocument();
                $dom->loadXML($xmlContent);
                
                $textNodes = $dom->getElementsByTagName('t');
                $text = '';
                
                foreach ($textNodes as $node) {
                    $text .= $node->nodeValue . ' ';
                }
                
                // Clean up text
                $text = preg_replace('/\s+/', ' ', $text);
                return trim($text);
                
            } catch (\Exception $e) {
                // Fallback: simple strip_tags
                $text = strip_tags($xmlContent);
                $text = html_entity_decode($text);
                $text = preg_replace('/\s+/', ' ', $text);
                return trim($text);
            }
        }

        throw new \Exception('Supported file formats: .txt, .pdf, and .docx');
    }

    // Perbarui fungsi analyzeSentiment secara keseluruhan
    private function analyzeSentiment($text)
    {
        $apiKey = \Illuminate\Support\Facades\Config::get('app.gemini_api_key') ?? $_ENV['GEMINI_API_KEY'] ?? '';
        $apiUrl = \Illuminate\Support\Facades\Config::get('app.gemini_api_url') ?? $_ENV['GEMINI_API_URL'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent';

        // PROMPT JSON LENGKAP
        $prompt = "Analisis sentimen, entitas, dan tema utama dari berita berikut: '{$text}'. " .
                  "Hasilkan output HANYA dalam format JSON. Jangan ada teks atau penjelasan lain di luar objek JSON. " .
                  "JSON harus memiliki kunci-kunci berikut: 'sentiment', 'score' (-1.0 hingga +1.0), 'details' (alasan mendalam), 'entitas', dan 'tema'. " .
                  "Untuk 'entitas' dan 'tema', gunakan array objek di mana setiap objek memiliki kunci: 'nama' (string), 'magnitudo' (float), dan 'skor_sentimen' (float).";

        try {
            $response = Http::timeout(30)->post("{$apiUrl}?key={$apiKey}", [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]);

            if (!$response->successful()) {
                // Tambahkan log ini:
                \Illuminate\Support\Facades\Log::error('GEMINI API CALL FAILED:', [
                    'status' => $response->status(), 
                    'body' => $response->body() // Catat body untuk melihat error dari Gemini
                ]);
                
                return $this->simpleSentimentAnalysis($text, true); 
            }

            if ($response->successful()) {
                $result = $response->json();
                $geminiText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
                
                $geminiData = json_decode($geminiText, true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($geminiData)) {
                    // Fallback jika parsing JSON gagal
                    return $this->simpleSentimentAnalysis($text, true); // true = minta data lengkap (entitas/tema kosong)
                }

                return [
                    'sentiment' => $geminiData['sentiment'] ?? 'Neutral',
                    'score' => $geminiData['score'] ?? 0.5,
                    'details' => $geminiData['details'] ?? 'Analisis detail tidak tersedia.',
                    'entitas' => $geminiData['entitas'] ?? [], // Penting: Entitas & Tema dari Gemini
                    'tema' => $geminiData['tema'] ?? []
                ];

            } else {
                return $this->simpleSentimentAnalysis($text, true); 
            }
        } catch (\Exception $e) {
            return $this->simpleSentimentAnalysis($text, true);
        }
    }


    // private function parseGeminiResponse($geminiText)
    // {
    //     // Ekstrak sentimen dari response Gemini
    //     if (stripos($geminiText, 'positif') !== false) {
    //         $sentiment = 'Positive';
    //         $score = 0.75;
    //     } elseif (stripos($geminiText, 'negatif') !== false) {
    //         $sentiment = 'Negative';
    //         $score = 0.25;
    //     } else {
    //         $sentiment = 'Neutral';
    //         $score = 0.5;
    //     }

    //     return [
    //         'sentiment' => $sentiment,
    //         'score' => $score,
    //         'details' => $geminiText
    //     ];
    // }

  
    private function simpleSentimentAnalysis($text, $fullData = false)
    {
        // Fallback sederhana jika Gemini error
        $positiveWords = ['baik', 'bagus', 'hebat', 'senang', 'sukses', 'positif', 'maju', 'unggul', 'meningkat', 'berkembang'];
        $negativeWords = ['buruk', 'jelek', 'gagal', 'sedih', 'negatif', 'mundur', 'korupsi', 'menurun', 'rugi'];

        $text = strtolower($text);
        $positiveCount = 0;
        $negativeCount = 0;

        foreach ($positiveWords as $word) {
            $positiveCount += substr_count($text, $word);
        }

        foreach ($negativeWords as $word) {
            $negativeCount += substr_count($text, $word);
        }

        if ($positiveCount > $negativeCount) {
            $result = ['sentiment' => 'Positive', 'score' => 0.7, 'details' => 'Analisis fallback: ditemukan kata positif'];
        } elseif ($negativeCount > $positiveCount) {
            $result = ['sentiment' => 'Negative', 'score' => 0.3, 'details' => 'Analisis fallback: ditemukan kata negatif'];
        } else {
            $result = ['sentiment' => 'Neutral', 'score' => 0.5, 'details' => 'Analisis fallback: netral (jumlah kata positif/negatif seimbang atau tidak ada)'];
        }

        // BAGIAN KRITIS: Menambahkan kunci Entitas dan Tema kosong saat diminta data lengkap
        if ($fullData) {
            $result['entitas'] = [];
            $result['tema'] = [];
        }
        
        return $result;
    }

    private function analyzeReadability($text)
    {
        return $this->fleschReadingEase($text);
    }

    private function fleschReadingEase($text)
    {
        $words = str_word_count($text, 1);
        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = max(1, count($words));
        $sentenceCount = max(1, count($sentences));
        $syllableCount = 0;
        $totalCharLength = 0;

        foreach ($words as $word) {
            $syllableCount += $this->countSyllables($word);
            $totalCharLength += strlen($word);
        }

        $avgWordLength = $wordCount > 0 ? round($totalCharLength / $wordCount, 2) : 0;
        $avgSentenceLength = $sentenceCount > 0 ? round($wordCount / $sentenceCount, 2) : 0;

        $score = 206.835
             - (1.015 * ($wordCount / $sentenceCount))
             - (84.6 * ($syllableCount / $wordCount));

        return [
            'score' => round($score, 2),
            'word_count' => $wordCount,
            'sentence_count' => $sentenceCount,
            'syllable_count' => $syllableCount, // BARU
            'avg_word_length' => $avgWordLength, // BARU
            'avg_sentence_length' => $avgSentenceLength // BARU
        ];
    }
    // Hapus atau abaikan fungsi analyzeReadability yang lama jika masih ada.

    private function getReadabilityCategory($score)
    {
        if ($score >= 90) return 'Sangat Mudah';
        if ($score >= 80) return 'Mudah';
        if ($score >= 70) return 'Cukup Mudah';
        if ($score >= 60) return 'Standar';
        if ($score >= 50) return 'Cukup Sulit';
        if ($score >= 30) return 'Sulit';
        return 'Sangat Sulit';
    }

    private function countSyllables($word)
    {
        $vowels = ['a', 'e', 'i', 'o', 'u', 'y'];
        $syllables = 0;
        $previousCharIsVowel = false;

        for ($i = 0; $i < strlen($word); $i++) {
            $char = strtolower($word[$i]);
            if (in_array($char, $vowels)) {
                if (!$previousCharIsVowel) {
                    $syllables++;
                }
                $previousCharIsVowel = true;
            } else {
                $previousCharIsVowel = false;
            }
        }

        return max(1, $syllables);
    }
}
