<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Agent Voice Assistant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
    <style>
        /* Style untuk indikator merekam */
        .recording {
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="card p-4 shadow-sm">
            <h1 class="mb-4 text-center">AI Agent Voice Assistant 🗣️</h1>

            <div class="text-center mb-3">
                <button id="recordButton" class="btn btn-danger btn-lg rounded-circle" style="width: 80px; height: 80px;">
                    🎤
                </button>
                <p id="status" class="mt-2 text-muted">Tekan untuk berbicara</p>
            </div>

            <div id="responseContainer" class="mt-4 p-3 bg-white border rounded">
                <p><strong>Anda berkata:</strong> <span id="userInputText" class="text-primary"></span></p>
                <hr>
                <p><strong>Respons Agen:</strong> <span id="responseText"></span></p>
                <div id="audioPlayerContainer" class="mt-2">
                    </div>
                <div id="errorMessage" class="text-danger mt-2"></div>
            </div>
        </div>
    </div>

    <script>
        // Cek dukungan browser untuk Web Speech API
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        let recognition;

        if (SpeechRecognition) {
            recognition = new SpeechRecognition();
            recognition.continuous = false; // Hanya menangkap satu kalimat
            recognition.lang = 'id-ID';     // Set bahasa ke Indonesia

            const recordButton = document.getElementById('recordButton');
            const statusDiv = document.getElementById('status');
            const userInputTextSpan = document.getElementById('userInputText');

            // Ketika tombol rekam diklik
            recordButton.addEventListener('click', () => {
                if (recordButton.classList.contains('recording')) {
                    recognition.stop();
                } else {
                    recognition.start();
                }
            });

            // Saat mulai merekam
            recognition.onstart = () => {
                statusDiv.textContent = 'Mendengarkan...';
                recordButton.classList.add('recording');
                recordButton.innerHTML = '...';
            };

            // Saat berhenti merekam
            recognition.onend = () => {
                statusDiv.textContent = 'Tekan untuk berbicara';
                recordButton.classList.remove('recording');
                recordButton.innerHTML = '🎤';
            };

            // Saat mendapatkan hasil transkripsi
            recognition.onresult = (event) => {
                const transcript = event.results[0][0].transcript;
                userInputTextSpan.textContent = transcript;
                
                // Panggil fungsi untuk mengirim teks ke Laravel
                sendTextToAI(transcript); 
            };

            // Penanganan error
            recognition.onerror = (event) => {
                console.error("Speech recognition error", event.error);
                statusDiv.textContent = `Error: ${event.error}`;
            };

        } else {
            // Jika browser tidak mendukung
            document.getElementById('status').textContent = "Maaf, browser Anda tidak mendukung fitur input suara.";
            document.getElementById('recordButton').disabled = true;
        }


        // **FUNGSI INI TETAP SAMA SEPERTI SEBELUMNYA**
        // Fungsi untuk mengirim teks ke backend Laravel
        async function sendTextToAI(message) {
            const responseTextSpan = document.getElementById('responseText');
            const audioPlayerContainer = document.getElementById('audioPlayerContainer');
            const errorMessageDiv = document.getElementById('errorMessage');
            const statusDiv = document.getElementById('status');

            // Reset tampilan
            responseTextSpan.textContent = '';
            audioPlayerContainer.innerHTML = '';
            errorMessageDiv.textContent = '';
            statusDiv.textContent = 'Agen sedang berpikir...';

            try {
                const response = await axios.post('/api/ai-agent/speak', {
                    message: message
                });

                const data = response.data;
                responseTextSpan.textContent = data.response_text;

                if (data.audio_url) {
                    audioPlayerContainer.innerHTML = `<audio controls autoplay><source src="${data.audio_url}" type="audio/mpeg"></audio>`;
                } else {
                    errorMessageDiv.textContent = 'Error: File audio tidak diterima.';
                }
                statusDiv.textContent = 'Tekan untuk berbicara';

            } catch (error) {
                console.error('Error:', error);
                const errorMsg = (error.response && error.response.data && error.response.data.error)
                                 ? error.response.data.error
                                 : 'Terjadi kesalahan saat memproses permintaan.';
                errorMessageDiv.textContent = 'Error: ' + errorMsg;
                statusDiv.textContent = 'Tekan untuk berbicara';
            }
        }
    </script>
</body>
</html>