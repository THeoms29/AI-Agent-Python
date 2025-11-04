<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use GuzzleHttp\Exception\GuzzleException;

class AIAgentController extends Controller
{
    // === STT: upload audio -> ElevenLabs STT (first try Python helper, then HTTP fallback) ===
    public function recognizeAudio(Request $request)
    {
        $request->validate([
            'audio' => 'required|file|mimes:mp3,wav,ogg,mp4|max:51200' // adjust limit as needed (50MB)
        ]);

        // store uploaded file in storage/app/public/uploads
        $stored = $request->file('audio')->store('uploads', 'public');
        $fullPath = storage_path('app/public/' . $stored);

        // Option: try python helper (if you want python stt script)
        $pythonExe = base_path('python_scripts/venv/Scripts/python.exe'); // Windows default venv path
        $script = base_path('python_scripts/stt.py');

        if (file_exists($pythonExe) && file_exists($script)) {
            try {
                $proc = new Process([$pythonExe, $script, $fullPath]);
                $proc->setTimeout(300);
                $proc->setEnv([
                    'ELEVENLABS_API_KEY' => env('ELEVENLABS_API_KEY')
                ]);
                $proc->run();

                Log::info('STT python stdout: ' . $proc->getOutput());
                if ($proc->getErrorOutput()) {
                    Log::warning('STT python stderr: ' . $proc->getErrorOutput());
                }

                if ($proc->isSuccessful()) {
                    $out = json_decode($proc->getOutput(), true);
                    if ($out && isset($out['success']) && $out['success'] === true) {
                        $text = $out['transcript'] ?? ($out['text'] ?? null);
                        return response()->json(['success' => true, 'text' => $text, 'stored' => $stored]);
                    }
                    Log::warning('STT python returned invalid JSON or success=false: ' . $proc->getOutput());
                    // fallthrough to HTTP fallback
                }
            } catch (\Exception $e) {
                Log::error('Python STT helper failed: ' . $e->getMessage());
                // fallthrough to HTTP fallback
            }
        }

        // HTTP fallback: call ElevenLabs STT via multipart/form-data
        $client = new Client(['base_uri' => 'https://api.elevenlabs.io', 'timeout' => 300]);

        try {
            $response = $client->request('POST', '/v1/speech-to-text/convert', [
                'headers' => [
                    'xi-api-key' => env('ELEVENLABS_API_KEY')
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($fullPath, 'rb'),
                        'filename' => basename($fullPath)
                    ],
                    [
                        'name' => 'model_id',
                        'contents' => 'scribe_v1'
                    ]
                ]
            ]);

            $body = json_decode((string)$response->getBody(), true);
            $text = $body['text'] ?? ($body['transcript'] ?? null);
            return response()->json(['success' => true, 'text' => $text, 'raw' => $body, 'stored' => $stored]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = (string)$e->getResponse()->getBody();
            Log::error('STT HTTP client error: ' . $body);
            return response()->json(['success' => false, 'error' => 'stt_client_error', 'details' => json_decode($body, true) ?: $body], 400);
        } catch (\Exception $e) {
            Log::error('STT HTTP error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'stt_error', 'details' => $e->getMessage()], 500);
        }
    }

    // === Agent: text -> ElevenLabs Agents simulate-conversation ===
    public function agentConverse(Request $request)
    {
        $request->validate(['text' => 'required|string']);
        $text = $request->input('text');

        $apiKey = env('ELEVENLABS_API_KEY');
        $agentId = env('ELEVENLABS_AGENT_ID');
        
        if (!$apiKey) {
            Log::error('Missing ELEVENLABS_API_KEY');
            return response()->json(['success' => false, 'error' => 'Missing ELEVENLABS_API_KEY', 'message' => 'API Key ElevenLabs tidak diatur di file .env'], 500);
        }
        
        if (!$agentId) {
            Log::error('Missing ELEVENLABS_AGENT_ID');
            return response()->json(['success' => false, 'error' => 'Missing ELEVENLABS_AGENT_ID', 'message' => 'Agent ID ElevenLabs tidak diatur di file .env'], 500);
        }

        $client = new Client(['base_uri' => 'https://api.elevenlabs.io', 'timeout' => 60]);

        try {
            $headers = [
                'xi-api-key' => $apiKey,
                'Content-Type' => 'application/json'
            ];

            // Attempt 1: Format dengan simulation_specification dan messages
            $payload1 = [
                'simulation_specification' => [
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'input_text', 'text' => $text]
                            ]
                        ]
                    ]
                ]
            ];

            try {
                Log::info('Attempting payload 1: ' . json_encode($payload1));
                $resp = $client->post("/v1/convai/agents/{$agentId}/simulate-conversation", [
                    'headers' => $headers,
                    'json' => $payload1
                ]);
            } catch (\GuzzleHttp\Exception\ClientException $e1) {
                Log::warning('Payload 1 failed, trying payload 2');
                // Attempt 2: Format dengan simulation_specification dan simulated_user_config
                $payload2 = [
                    'simulation_specification' => [
                        'simulated_user_config' => [
                            'messages' => [
                                [
                                    'role' => 'user',
                                    'content' => [
                                        ['type' => 'input_text', 'text' => $text]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];

                try {
                    Log::info('Attempting payload 2: ' . json_encode($payload2));
                    $resp = $client->post("/v1/convai/agents/{$agentId}/simulate-conversation", [
                        'headers' => $headers,
                        'json' => $payload2
                    ]);
                } catch (\GuzzleHttp\Exception\ClientException $e2) {
                    Log::warning('Payload 2 failed, trying payload 3');
                    // Attempt 3: Format sederhana dengan simulation_specification kosong
                    $payload3 = [
                        'simulation_specification' => [
                            'simulated_user_config' => []
                        ],
                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => [
                                    ['type' => 'input_text', 'text' => $text]
                                ]
                            ]
                        ]
                    ];

                    Log::info('Attempting payload 3: ' . json_encode($payload3));
                    $resp = $client->post("/v1/convai/agents/{$agentId}/simulate-conversation", [
                        'headers' => $headers,
                        'json' => $payload3
                    ]);
                }
            }

            $j = json_decode((string)$resp->getBody(), true);
            Log::info('Agent response received: ' . json_encode($j));

            // Heuristic to extract agent text
            $agentText = null;
            $conversation = null;
            if (isset($j['messages']) && is_array($j['messages'])) {
                $conversation = $j['messages'];
            } elseif (isset($j['simulated_conversation']) && is_array($j['simulated_conversation'])) {
                $conversation = $j['simulated_conversation'];
            }

            if ($conversation) {
                foreach (array_reverse($conversation) as $m) {
                    if (in_array($m['role'] ?? '', ['assistant', 'agent', 'system', 'bot'])) {
                        // Prefer explicit 'message' field if present
                        if (isset($m['message']) && is_string($m['message'])) {
                            $agentText = $m['message'];
                            break;
                        }

                        $content = $m['content'] ?? ($m['text'] ?? null);
                        
                        // Handle content as array (structured content)
                        if (is_array($content)) {
                            foreach ($content as $block) {
                                if (is_array($block) && isset($block['text'])) {
                                    $agentText = $block['text'];
                                    break;
                                } elseif (is_string($block) && trim($block) !== '') {
                                    $agentText = $block;
                                    break;
                                }
                            }
                        } elseif (is_string($content) && trim($content) !== '') {
                            $agentText = $content;
                        }
                        
                        if ($agentText) break;
                    }
                }
            }
            if (!$agentText) {
                $agentText = $j['response'] ?? $j['text'] ?? json_encode($j);
            }

            // Clean up: remove any JSON encoding artifacts
            if (is_string($agentText) && (strpos($agentText, '{') === 0 || strpos($agentText, '[') === 0)) {
                $decoded = json_decode($agentText, true);
                if ($decoded !== null) {
                    $agentText = is_array($decoded) ? json_encode($decoded) : $decoded;
                }
            }

            Log::info('Extracted agent text length: ' . strlen($agentText ?? ''));
            // Return short display text by default (UI-friendly)
            $MAX_DISPLAY = 500;
            $displayText = $agentText;
            $wasTruncated = false;
            if (is_string($displayText) && strlen($displayText) > $MAX_DISPLAY) {
                $displayText = substr($displayText, 0, $MAX_DISPLAY - 3) . '...';
                $wasTruncated = true;
            }
            return response()->json(['success' => true, 'text' => $displayText, 'raw' => $j, 'full_text_truncated' => $wasTruncated]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response ? $response->getStatusCode() : 0;
            $body = $response ? (string)$response->getBody() : '';
            
            $errorData = json_decode($body, true);
            $errorMessage = 'Error dari ElevenLabs API';
            
            if ($errorData && isset($errorData['detail'])) {
                $errorMessage = $errorData['detail']['message'] ?? $errorData['detail'] ?? $errorMessage;
            } elseif ($errorData && isset($errorData['message'])) {
                $errorMessage = $errorData['message'];
            } elseif ($errorData && isset($errorData['error'])) {
                $errorMessage = $errorData['error']['message'] ?? $errorData['error'] ?? $errorMessage;
            }
            
            Log::error('Agent client exception [Status: ' . $statusCode . ']: ' . $body);
            
            return response()->json([
                'success' => false, 
                'error' => 'agent_client_error',
                'message' => $errorMessage,
                'status_code' => $statusCode,
                'details' => $errorData ?: $body
            ], 400);
        } catch (\Exception $e) {
            Log::error('Agent HTTP error: ' . $e->getMessage());
            return response()->json([
                'success' => false, 
                'error' => 'agent_error', 
                'message' => $e->getMessage(),
                'details' => $e->getMessage()
            ], 500);
        }
    }

    // === TTS: text -> ElevenLabs TTS, save to file, return public URL ===
    public function ttsViaPhp(Request $request)
    {
        $request->validate(['text' => 'required|string']);
        $text = $request->input('text');
        
        // ElevenLabs TTS limit: 10000 characters
        $MAX_CHARS = 10000;
        $textLength = mb_strlen($text);
        
        // If text is too long, truncate it with a note
        if ($textLength > $MAX_CHARS) {
            Log::warning("Text too long for TTS ({$textLength} chars), truncating to {$MAX_CHARS} chars");
            $text = mb_substr($text, 0, $MAX_CHARS - 50) . '... [Teks dipotong karena terlalu panjang untuk audio]';
            $textLength = mb_strlen($text);
        }

        $voiceId = env('ELEVENLABS_VOICE_ID');
        if (!$voiceId) {
            return response()->json(['success' => false, 'error' => 'Missing ELEVENLABS_VOICE_ID'], 500);
        }

        $filename = 'ai_audio_' . time() . '_' . substr(sha1($text), 0, 8) . '.mp3';
        $saveDir = storage_path('app/public/ai_audio');
        @mkdir($saveDir, 0755, true);
        $savePath = $saveDir . DIRECTORY_SEPARATOR . $filename;

        $client = new Client(['base_uri' => 'https://api.elevenlabs.io', 'timeout' => 120]);

        try {
            $resp = $client->post("/v1/text-to-speech/{$voiceId}", [
                'headers' => [
                    'xi-api-key' => env('ELEVENLABS_API_KEY'),
                    'Content-Type' => 'application/json'
                ],
                'json' => ['text' => $text],
                'sink' => $savePath
            ]);

            if ($resp->getStatusCode() === 200) {
                // ensure file exists
                if (!file_exists($savePath)) {
                    Log::error('TTS responded 200 but file not found: ' . $savePath);
                    return response()->json(['success' => false, 'error' => 'tts_file_missing'], 500);
                }
                $publicUrl = asset('storage/ai_audio/' . $filename);
                return response()->json(['success' => true, 'audio_url' => $publicUrl, 'path' => $savePath]);
            }

            return response()->json(['success' => false, 'status' => $resp->getStatusCode(), 'body' => (string)$resp->getBody()], 500);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = (string)$e->getResponse()->getBody();
            Log::error('TTS client exception: ' . $body);
            return response()->json(['success' => false, 'error' => 'tts_client_error', 'details' => json_decode($body, true) ?: $body], 400);
        } catch (\Exception $e) {
            Log::error('TTS HTTP error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'tts_error', 'details' => $e->getMessage()], 500);
        }
    }

    // === Full convenience: audio or text -> STT -> Agent -> TTS ===
    public function converseFull(Request $request)
    {
        // If file uploaded, do STT
        if ($request->hasFile('audio')) {
            $sttResponse = $this->recognizeAudio($request);
            $sttJson = json_decode($sttResponse->getContent(), true);
            if (!$sttJson['success']) {
                return $sttResponse;
            }
            $text = $sttJson['text'];
        } else {
            $request->validate(['text' => 'required|string']);
            $text = $request->input('text');
        }

        // Agent
        $agentResp = $this->agentConverse(new Request(['text' => $text]));
        $agentJson = json_decode($agentResp->getContent(), true);
        if (!$agentJson['success']) {
            return $agentResp;
        }
        // Use the short display text from agentConverse, but prefer full text if not truncated
        $agentText = $agentJson['text'];

        // TTS - dengan handling untuk teks panjang dan batasi teks yang dibacakan
        $MAX_SPOKEN = 800; // sekitar beberapa kalimat
        $spokenText = is_string($agentText) && mb_strlen($agentText) > $MAX_SPOKEN
            ? (mb_substr($agentText, 0, $MAX_SPOKEN - 3) . '...')
            : $agentText;
        $ttsResp = $this->ttsViaPhp(new Request(['text' => $spokenText]));
        $ttsJson = json_decode($ttsResp->getContent(), true);
        
        // Combine response: selalu include agent text, audio optional
        if ($ttsJson && $ttsJson['success']) {
            return response()->json([
                'success' => true,
                'response_text' => $spokenText,
                'audio_url' => $ttsJson['audio_url'],
                'path' => $ttsJson['path'] ?? null,
                'text_truncated' => mb_strlen($agentText) > $MAX_SPOKEN
            ]);
        } else {
            // Jika TTS gagal (misal karena terlalu panjang), tetap kembalikan teks
            Log::warning('TTS failed but returning text response: ' . ($ttsJson['error'] ?? 'Unknown error'));
            return response()->json([
                'success' => true,
                'response_text' => $spokenText,
                'audio_url' => null,
                'tts_error' => $ttsJson['error'] ?? 'TTS generation failed',
                'text_truncated' => mb_strlen($agentText) > $MAX_SPOKEN,
                'message' => 'Audio tidak dapat dibuat karena teks terlalu panjang. Teks tetap ditampilkan.'
            ]);
        }
    }
}
