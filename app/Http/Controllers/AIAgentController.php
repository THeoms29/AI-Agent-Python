<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class AIAgentController extends Controller
{
    public function speak(Request $request)
    {
        // terima 'message' atau 'text'
        $message = $request->input('message', $request->input('text', null));
        if (!$message) {
            return response()->json(['message' => 'The message field is required.'], 422);
        }

        // path ke python executable di venv Anda
        $pythonExe = base_path('python_scripts/venv/Scripts/python.exe');
        $pythonScript = base_path('python_scripts/main.py');

        // Build process
        $process = new Process([$pythonExe, $pythonScript, $message]);
        $process->setTimeout(120); // 2 minutes
        // Pas env agar python punya API key
        $process->setEnv([
            'ELEVENLABS_API_KEY' => env('ELEVENLABS_API_KEY'),
            'ELEVENLABS_AGENT_ID' => env('ELEVENLABS_AGENT_ID'),
            'ELEVENLABS_VOICE_ID' => env('ELEVENLABS_VOICE_ID'),
        ]);

        try {
            $process->run();

            // always log stdout/stderr for debugging
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            Log::info('AI Agent python stdout: ' . $stdout);
            if (!empty($stderr)) {
                Log::error('AI Agent python stderr: ' . $stderr);
            }

            // If process not successful, return helpful error with captured stderr
            if (!$process->isSuccessful()) {
                // try parse stdout as json for more info
                $parsed = null;
                try {
                    $parsed = json_decode($stdout, true);
                } catch (\Throwable $ex) {
                    $parsed = null;
                }

                $errMsg = $parsed['error'] ?? 'Gagal menjalankan agen AI. Silakan coba lagi.';
                return response()->json(['error' => $errMsg, 'details' => $parsed ?? $stderr], 500);
            }

            // if successful, parse stdout (should be json)
            $result = json_decode($stdout, true);
            if (!$result) {
                // fallback: return raw stdout
                return response()->json(['success' => false, 'error' => 'Python returned non-json output', 'raw' => $stdout], 500);
            }

            if (isset($result['success']) && $result['success'] === true && !empty($result['audio_path'])) {
                $audioPath = base_path($result['audio_path']);
                // If path is relative to script folder, adjust:
                if (!file_exists($audioPath)) {
                    // try relative to python_scripts folder
                    $audioPath = base_path('python_scripts/' . $result['audio_path']);
                }
                if (file_exists($audioPath)) {
                    // return JSON with URL (or stream file)
                    // simple: return path (you can serve this statically)
                    return response()->json([
                        'success' => true,
                        'response_text' => $result['response_text'] ?? null,
                        'audio_path' => $audioPath,
                        'method' => $result['method'] ?? null
                    ]);
                } else {
                    return response()->json(['success' => false, 'error' => 'Audio file not found', 'python' => $result], 500);
                }
            }

            // if python responded but success false
            return response()->json($result, 200);

        } catch (ProcessFailedException $e) {
            Log::error('Python Process Failed: ' . $e->getMessage());
            return response()->json(['error' => 'Gagal menjalankan agen AI. Silakan coba lagi.', 'details' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Exception running python process: ' . $e->getMessage());
            return response()->json(['error' => 'Gagal menjalankan agen AI. Silakan coba lagi.', 'details' => $e->getMessage()], 500);
        }
    }

    // optional test endpoint
    public function test()
    {
        return response()->json(['ok' => true]);
    }
}
