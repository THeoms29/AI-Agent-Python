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

        $agentId = env('ELEVENLABS_AGENT_ID');
        if (!$agentId) {
            return response()->json(['success' => false, 'error' => 'Missing ELEVENLABS_AGENT_ID'], 500);
        }

        $client = new Client(['base_uri' => 'https://api.elevenlabs.io', 'timeout' => 60]);

        try {
            $payload = [
                'messages' => [
                    ['role' => 'user', 'content' => $text]
                ]
            ];

            $resp = $client->post("/v1/convai/agents/{$agentId}/simulate-conversation", [
                'headers' => [
                    'xi-api-key' => env('ELEVENLABS_API_KEY'),
                    'Content-Type' => 'application/json'
                ],
                'json' => $payload
            ]);

            $j = json_decode((string)$resp->getBody(), true);

            // Heuristic to extract agent text
            $agentText = null;
            if (isset($j['messages']) && is_array($j['messages'])) {
                foreach (array_reverse($j['messages']) as $m) {
                    if (in_array($m['role'] ?? '', ['assistant', 'agent', 'system', 'bot'])) {
                        $agentText = $m['content'] ?? ($m['text'] ?? $agentText);
                        if ($agentText) break;
                    }
                }
            }
            if (!$agentText) {
                $agentText = $j['response'] ?? $j['text'] ?? json_encode($j);
            }

            return response()->json(['success' => true, 'text' => $agentText, 'raw' => $j]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = (string)$e->getResponse()->getBody();
            Log::error('Agent client exception: ' . $body);
            return response()->json(['success' => false, 'error' => 'agent_client_error', 'details' => json_decode($body, true) ?: $body], 400);
        } catch (\Exception $e) {
            Log::error('Agent HTTP error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'agent_error', 'details' => $e->getMessage()], 500);
        }
    }

    // === TTS: text -> ElevenLabs TTS, save to file, return public URL ===
    public function ttsViaPhp(Request $request)
    {
        $request->validate(['text' => 'required|string']);
        $text = $request->input('text');

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
        $agentText = $agentJson['text'];

        // TTS
        $ttsResp = $this->ttsViaPhp(new Request(['text' => $agentText]));
        return $ttsResp;
    }
}
