<?php
/**
 * Intelligent Voicemail - Medical Clinic Triage Portal
 * Stack: Vanilla PHP 8.2, Tailwind CSS, LocalStorage, Streaming AI
 */

// --- DEBUGGING ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- CONFIGURATION ---
$openRouterApiKey = 'YOUR_OPENROUTER_API_KEY';

// --- API LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    if ($_POST['action'] === 'process_audio' || $_POST['action'] === 'process_direct') {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        function sendUpdate($event, $data) {
            echo "event: $event\ndata: " . json_encode($data) . "\n\n";
            while (ob_get_level()) ob_end_flush();
            flush();
        }

        try {
            $displayFilename = $_POST['filename'] ?? 'Uploaded Voicemail';
            $filename = $displayFilename;
            $extension = 'wav';

            if ($_POST['action'] === 'process_audio') {
                $audioPath = __DIR__ . '/audio/' . $filename;
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                sendUpdate('status', 'Reading audio...');
                $audioData = file_get_contents($audioPath);
                if ($audioData === false) throw new Exception("File not found.");
            } else {
                if (!isset($_FILES['audio_file'])) throw new Exception("No file uploaded.");
                $displayFilename = $_FILES['audio_file']['name'];
                $extension = strtolower(pathinfo($displayFilename, PATHINFO_EXTENSION));
                $filename = 'upload_' . bin2hex(random_bytes(4)) . '_' . time() . '.' . $extension;
                $audioData = file_get_contents($_FILES['audio_file']['tmp_name']);
                sendUpdate('status', 'Processing upload...');
            }
            
            $audioBase64 = base64_encode($audioData);
            
            sendUpdate('status', 'Transcribing...');
            $ch = curl_init('https://openrouter.ai/api/v1/audio/transcriptions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $openRouterApiKey
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'model' => 'google/chirp-3',
                'input_audio' => ['data' => $audioBase64, 'format' => $extension]
            ]));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $transcriptionData = json_decode($response, true);
            if ($httpCode !== 200) throw new Exception("API Error: " . ($transcriptionData['error']['message'] ?? $response));

            $rawText = $transcriptionData['text'] ?? '';
            sendUpdate('transcript_ready', $rawText);

            sendUpdate('status', 'Analyzing...');
            $prompt = "Analyze this medical voicemail and return ONLY a valid JSON object. Do not include markdown formatting or emdashes.
            Transcript: \"$rawText\"
            JSON Schema: { \"patient_name\": \"...\", \"dob\": \"...\", \"intent\": \"...\", \"urgency\": \"High|Medium|Low\", \"justification\": \"...\", \"summary\": \"...\", \"next_action\": \"...\" }";

            $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $openRouterApiKey
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'model' => 'google/gemini-3.1-flash-lite',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'stream' => true,
                'response_format' => ['type' => 'json_object']
            ]));

            $fullTriageResponse = '';
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$fullTriageResponse) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    if (str_starts_with($line, 'data: ')) {
                        $jsonStr = substr($line, 6);
                        if ($jsonStr === '[DONE]') continue;
                        $decoded = json_decode($jsonStr, true);
                        $content = $decoded['choices'][0]['delta']['content'] ?? '';
                        if ($content) {
                            $fullTriageResponse .= $content;
                            echo "event: triage_delta\ndata: " . json_encode($content) . "\n\n";
                            while (ob_get_level()) ob_end_flush();
                            flush();
                        }
                    }
                }
                return strlen($data);
            });

            curl_exec($ch);
            curl_close($ch);

            $structuredResult = json_decode($fullTriageResponse, true);
            sendUpdate('complete', [
                'id' => uniqid('vm_'),
                'timestamp' => date('M j, g:i a'),
                'filename' => $filename,
                'display_name' => $displayFilename,
                'transcript' => $rawText,
                'triage' => $structuredResult,
                'status' => 'New',
                'isAdHoc' => ($_POST['action'] === 'process_direct'),
                'adHocData' => ($_POST['action'] === 'process_direct' ? 'data:audio/' . $extension . ';base64,' . $audioBase64 : null)
            ]);
        } catch (Exception $e) {
            sendUpdate('error', $e->getMessage());
        }
        exit;
    }

    if ($_POST['action'] === 'list_audio') {
        $audioDir = __DIR__ . '/audio';
        if (!is_dir($audioDir)) {
            mkdir($audioDir, 0755, true);
        }
        $files = glob($audioDir . '/*.{wav,mp3,m4a,ogg,aac,webm}', GLOB_BRACE) ?: [];
        echo json_encode(array_values(array_map('basename', $files)));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heidi Calls | Harbour to Sunset GP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .urgency-High { border-left: 5px solid #ef4444; }
        .urgency-Medium { border-left: 5px solid #f59e0b; }
        .urgency-Low { border-left: 5px solid #10b981; }
        .status-Resolved { opacity: 0.6; filter: grayscale(0.4); transition: all 0.3s ease; }
        @keyframes pulse-bg { 0%, 100% { background-color: #f1f5f9; } 50% { background-color: #e2e8f0; } }
        .skeleton { animation: pulse-bg 2s infinite; border-radius: 6px; display: inline-block; min-height: 1em; width: 100%; }
        /* Custom Audio Slider Styling */
        input[type="range"] { accent-color: #4f46e5; }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
    </style>
</head>
<body class="bg-[#F8FAFC] text-slate-900 min-h-screen pb-20">

    <nav class="bg-white border-b border-slate-200 px-4 md:px-8 py-4 sticky top-0 z-40 backdrop-blur-md bg-white/90">
        <div class="max-w-6xl mx-auto flex justify-between items-center gap-4">
            <div class="flex items-center gap-3">
                <div class="bg-indigo-600 text-white p-2 rounded-xl shadow-indigo-100 shadow-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" /></svg>
                </div>
                <div>
                    <h1 class="text-lg font-black text-slate-900 leading-none tracking-tight">Heidi Calls</h1>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Harbour to Sunset GP</p>
                </div>
            </div>
            <div class="flex items-center gap-2 md:gap-4">
                <input type="file" id="adhoc-upload" class="hidden" accept="audio/*" onchange="uploadAdHoc(this)">
                <button onclick="document.getElementById('adhoc-upload').click()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition-all flex items-center gap-2 shadow-lg shadow-indigo-100 active:scale-95">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                    <span>Upload Voicemail</span>
                </button>
            </div>
        </div>
    </nav>

    <header class="max-w-6xl mx-auto px-4 md:px-8 pt-10 pb-2">
        <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight">Morning Triage</h2>
        <p class="text-slate-500 mt-2 font-medium">After-hours & overflow voicemails requiring admin action.</p>
    </header>

    <main class="max-w-6xl mx-auto p-4 md:p-8">
        <div id="upload-processing-zone" class="hidden mb-8">
            <!-- Ad-hoc uploads show up here as they process -->
        </div>
        <div id="voicemail-list" class="grid gap-6"></div>
    </main>

    <script>
        let filesOnDisk = [];
        let processedData = [];
        try {
            const stored = localStorage.getItem('clinic_voicemails');
            processedData = stored ? JSON.parse(stored) : [];
            if (!Array.isArray(processedData)) processedData = [];
        } catch (e) {
            console.error("Failed to load processedData:", e);
            processedData = [];
        }

        function cleanName(filename) {
            if (!filename) return "Unknown";
            return filename.replace(/\.[^/.]+$/, "").replace(/[-_]/g, " ");
        }

        function getItemHTML(item) {
            const safeId = item.filename.replace(/\W/g, '_');
            const isP = item.isProcessed;
            const isProcessing = item.isProcessing;
            const vm = isP ? item : null;
            const urgency = (isP && vm.triage) ? vm.triage.urgency : 'Pending';
            const urgencyColor = isP ? getUrgencyClass(urgency) : 'text-slate-400 bg-slate-100';
            const audioSrc = (isP && item.isAdHoc) ? item.adHocData : `audio/${item.filename}`;

            return `
                <div id="item-${safeId}" class="bg-white rounded-[2rem] border border-slate-200 p-6 md:p-8 transition-all relative shadow-sm hover:shadow-md ${isP ? 'urgency-' + urgency : 'border-l-[6px] border-l-slate-300'} ${isP && item.status === 'Resolved' ? 'status-Resolved' : ''} ${isProcessing ? 'animate-pulse' : ''}">
                    
                    <!-- Header Section -->
                    <div class="flex flex-col md:flex-row md:items-start justify-between gap-6 mb-8">
                        <div class="flex items-start gap-5">
                            <div class="w-14 h-14 rounded-2xl flex items-center justify-center shrink-0 transition-colors duration-500 triage-icon-bg ${isP ? urgencyColor.replace('text-', 'bg-').replace('700', '100') : 'bg-slate-100'}">
                                ${isProcessing 
                                    ? `<div class="animate-spin w-6 h-6 border-2 border-indigo-600 border-t-transparent rounded-full"></div>`
                                    : `<svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 triage-icon ${isP ? urgencyColor : 'text-slate-400'}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${isP ? 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z' : 'M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z'}" /></svg>`
                                }
                            </div>
                            <div class="overflow-hidden">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mb-1.5">
                                    <h3 class="font-extrabold text-slate-900 text-xl triage-name leading-none">${(isP && vm.triage) ? vm.triage.patient_name : cleanName(item.display_name || item.filename)}</h3>
                                    ${(isP && vm.triage && vm.triage.dob) ? `<span class="text-xs font-bold text-slate-400 triage-dob tracking-tight">DOB: ${vm.triage.dob}</span>` : ''}
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full border triage-urgency ${isP ? 'border-current ' + urgencyColor : 'border-slate-200 text-slate-400'} status-badge">${isProcessing ? 'Processing...' : (isP ? urgency + ' Priority' : 'Waiting')}</span>
                                    <span class="text-xs font-bold text-slate-600 triage-intent">${(isP && vm.triage) ? vm.triage.intent : (isProcessing ? 'Analyzing...' : 'Unprocessed')}</span>
                                    ${isP ? `<span class="text-[10px] text-slate-400 font-bold uppercase tracking-tighter">${vm.timestamp}</span>` : ''}
                                </div>
                            </div>
                        </div>

                        <!-- Custom Audio Player -->
                        <div class="bg-slate-50 border border-slate-100 p-2.5 rounded-2xl flex items-center gap-3 w-full md:w-auto md:min-w-[280px] ${isProcessing ? 'opacity-50 pointer-events-none' : ''}">
                            <button onclick="toggleAudio('${item.filename.replace(/'/g, "\\'")}', this, '${audioSrc}')" class="w-10 h-10 bg-indigo-600 text-white rounded-xl flex items-center justify-center shadow-lg shadow-indigo-100 hover:bg-indigo-700 active:scale-95 transition-all play-btn-${safeId}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" /></svg>
                            </button>
                            <div class="flex-1">
                                <input type="range" class="w-full h-1.5 appearance-none bg-slate-200 rounded-full cursor-pointer slider-${safeId}" value="0" 
                                    onmousedown="isSeeking['${item.filename.replace(/'/g, "\\'")}']=true" 
                                    onmouseup="isSeeking['${item.filename.replace(/'/g, "\\'")}']=false" 
                                    oninput="seekAudio('${item.filename.replace(/'/g, "\\'")}', this.value, '${audioSrc}')">
                                <div class="flex justify-between mt-1.5">
                                    <span class="text-[10px] font-black text-slate-400 uppercase time-${safeId}">0:00</span>
                                    <span class="text-[10px] font-black text-slate-300 uppercase">Voicemail</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="stream-zone ${(!isP && !isProcessing) ? 'hidden' : ''}">
                        <div class="grid lg:grid-cols-12 gap-8">
                            <div class="lg:col-span-8 space-y-8">
                                <div class="triage-summary-container">
                                    <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-3 flex items-center gap-2">
                                        <span class="w-1.5 h-1.5 bg-indigo-500 rounded-full"></span> Patient Summary
                                    </p>
                                    ${(isP && vm.triage) 
                                        ? `<p class="text-[15px] font-medium text-slate-700 leading-relaxed triage-summary-text">${vm.triage.summary}</p>`
                                        : `<div class="skeleton h-12 w-full triage-summary"></div>`
                                    }
                                </div>
                                <div class="triage-next-action-container">
                                    ${(isP && vm.triage)
                                        ? `<div class="bg-indigo-600 text-white px-5 py-4 rounded-2xl shadow-xl shadow-indigo-100 inline-block font-bold triage-next-action">&rarr; ${vm.triage.next_action}</div>`
                                        : `<div class="skeleton w-64 h-14 triage-next-action"></div>`
                                    }
                                </div>
                            </div>
                            <div class="lg:col-span-4 bg-slate-50 p-5 rounded-2xl border border-slate-100 triage-transcript">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Transcript Preview</p>
                                ${(isP && vm.transcript)
                                    ? `<div class="max-h-64 overflow-y-auto pr-2 custom-scrollbar"><p class="text-sm text-slate-500 italic triage-transcript-text">${vm.transcript}</p></div>`
                                    : `<div class="skeleton h-32 w-full"></div>`
                                }
                            </div>
                        </div>
                    </div>

                    ${isP ? `
                    <div class="flex items-center justify-end gap-3 mt-6 pt-6 border-t border-slate-100">
                        ${vm.status === 'Resolved' 
                            ? `<button onclick="updateStatus('${vm.id}', 'New')" class="text-sm font-bold text-slate-500 hover:text-slate-800 transition-colors">Reopen</button>` 
                            : `<button onclick="updateStatus('${vm.id}', 'Resolved')" class="text-sm font-bold text-emerald-600 hover:text-emerald-700 bg-emerald-50 hover:bg-emerald-100 px-4 py-2 rounded-xl transition-all">Mark Resolved</button>`}
                        <button onclick="deleteVoicemail('${vm.id}')" class="text-sm font-bold text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 px-4 py-2 rounded-xl transition-all">Delete</button>
                    </div>
                    ` : (isProcessing ? '' : `
                    <div class="flex justify-end border-t border-slate-100 pt-6 mt-6">
                        <button onclick="processFile('${item.filename.replace(/'/g, "\\'")}')" class="bg-indigo-600 text-white px-5 py-2.5 rounded-xl text-sm font-bold shadow-md shadow-indigo-100 hover:bg-indigo-700 active:scale-95 transition-all">
                            Start AI Triage
                        </button>
                    </div>
                    `)}
                </div>
            `;
        }

        function renderDashboard() {
            console.log("Rendering dashboard. Files on disk:", filesOnDisk);
            const list = document.getElementById('voicemail-list');
            if (!list) return;

            // Combine files from disk and ad-hoc files from processedData
            const diskItems = filesOnDisk.map(f => {
                const found = processedData.find(p => p.filename === f && !p.isAdHoc);
                return found ? { ...found, isProcessed: true } : { filename: f, isProcessed: false };
            });
            
            const adHocItems = processedData.filter(p => p.isAdHoc && !filesOnDisk.includes(p.filename))
                                           .map(p => ({ ...p, isProcessed: true }));
            
            let allItems = [...adHocItems, ...diskItems];

            // Sort logic: High > Medium > Low > Unprocessed
            const priorityMap = { 'High': 3, 'Medium': 2, 'Low': 1, 'Pending': 0 };
            allItems.sort((a, b) => {
                const pA = a.isProcessed ? (priorityMap[a.triage?.urgency] || 0) : -1;
                const pB = b.isProcessed ? (priorityMap[b.triage?.urgency] || 0) : -1;
                return pB - pA;
            });

            if (allItems.length === 0) {
                list.innerHTML = `
                    <div class="bg-white rounded-[2rem] p-12 text-center border border-dashed border-slate-300">
                        <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" /></svg>
                        </div>
                        <p class="text-slate-500 font-bold text-lg mb-1">Your inbox is empty</p>
                        <p class="text-slate-400 text-sm">Upload an audio file to get started.</p>
                    </div>`;
                return;
            }

            list.innerHTML = allItems.map(item => getItemHTML(item)).join('');
        }

        // --- Custom Audio Engine ---
        const audioNodes = {};
        const isSeeking = {};
        const playIcon = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" /></svg>';
        const pauseIcon = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 9v6m4-6v6" /></svg>';

        function initAudio(file, src) {
            if (audioNodes[file]) return audioNodes[file];
            const safeId = file.replace(/\W/g, '_');
            const a = new Audio(src);
            a.ontimeupdate = () => {
                if (isSeeking[file]) return;
                const slider = document.querySelector(`.slider-${safeId}`);
                const timeEl = document.querySelector(`.time-${safeId}`);
                if (slider && a.duration && isFinite(a.duration)) slider.value = (a.currentTime / a.duration) * 100;
                if (timeEl) {
                    const m = Math.floor(a.currentTime / 60);
                    const s = Math.floor(a.currentTime % 60);
                    timeEl.innerText = `${m}:${s < 10 ? '0' : ''}${s}`;
                }
            };
            a.onended = () => {
                const btn = document.querySelector(`.play-btn-${safeId}`);
                if (btn) btn.innerHTML = playIcon;
            };
            audioNodes[file] = a;
            return a;
        }

        function toggleAudio(file, btn, src) {
            const a = initAudio(file, src);
            if (a.paused) {
                Object.keys(audioNodes).forEach(f => { if(f !== file) { audioNodes[f].pause(); const otherBtn = document.querySelector(`.play-btn-${f.replace(/\W/g, '_')}`); if(otherBtn) otherBtn.innerHTML = playIcon; }});
                a.play().catch(e => console.error("Playback failed:", e));
                btn.innerHTML = pauseIcon;
            } else {
                a.pause();
                btn.innerHTML = playIcon;
            }
        }

        function seekAudio(file, val, src) {
            const a = initAudio(file, src);
            if (a.duration && isFinite(a.duration)) {
                a.currentTime = (val / 100) * a.duration;
            }
        }

        async function uploadAdHoc(input) {
            const file = input.files[0];
            if (!file) return;
            
            const filename = file.name;
            const safeId = filename.replace(/\W/g, '_');
            
            const fd = new FormData();
            fd.append('action', 'process_direct');
            fd.append('audio_file', file);
            fd.append('filename', filename);
            
            const zone = document.getElementById('voicemail-list');
            const emptyState = zone.querySelector('.text-center');
            if (emptyState) emptyState.remove();

            const wrapper = document.createElement('div');
            wrapper.innerHTML = getItemHTML({ filename: filename, isProcessed: false, isProcessing: true });
            const itemEl = wrapper.firstElementChild;
            zone.prepend(itemEl);

            await handleStreamingProcess(fd, filename, true);
        }

        async function processFile(filename) {
            const safeId = filename.replace(/\W/g, '_');
            const itemEl = document.getElementById(`item-${safeId}`);
            if (itemEl) {
                const wrapper = document.createElement('div');
                wrapper.innerHTML = getItemHTML({ filename: filename, isProcessed: false, isProcessing: true });
                itemEl.replaceWith(wrapper.firstElementChild);
            }

            const fd = new FormData();
            fd.append('action', 'process_audio');
            fd.append('filename', filename);
            await handleStreamingProcess(fd, filename, false);
        }

        async function handleStreamingProcess(fd, filename, isAdHoc) {
            const safeId = filename.replace(/\W/g, '_');
            let itemEl = document.getElementById(`item-${safeId}`);
            
            const badge = itemEl.querySelector('.status-badge');
            const nameEl = itemEl.querySelector('.triage-name');
            const iconBg = itemEl.querySelector('.triage-icon-bg');

            let triageText = "";
            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buffer = "";

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });
                    const parts = buffer.split("\n\n");
                    buffer = parts.pop();

                    for (const p of parts) {
                        if (!p.trim()) continue;
                        const match = p.match(/event: (.*)\ndata: (.*)/s);
                        if (!match) continue;
                        const ev = match[1].trim(); const data = JSON.parse(match[2].trim());

                        if (ev === 'status') { if(badge) badge.innerText = data; }
                        else if (ev === 'transcript_ready') {
                            const ts = itemEl.querySelector('.triage-transcript');
                            if(ts) { 
                                ts.innerHTML = `<p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Transcript Preview</p>
                                    <div class="max-h-64 overflow-y-auto pr-2 custom-scrollbar"><p class="text-sm text-slate-500 italic triage-transcript-text">${data}</p></div>`; 
                            }
                        }
                        else if (ev === 'triage_delta') {
                            triageText += data;
                            const nm = triageText.match(/"patient_name":\s*"([^"]+)"/);
                            if (nm && nameEl) nameEl.innerText = nm[1];
                            
                            const sm = triageText.match(/"summary":\s*"([^"]+)"/);
                            if (sm) { 
                                const container = itemEl.querySelector('.triage-summary-container');
                                if(container) { 
                                    container.innerHTML = `<p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-3 flex items-center gap-2"><span class="w-1.5 h-1.5 bg-indigo-500 rounded-full"></span> Patient Summary</p><p class="text-[15px] font-medium text-slate-700 leading-relaxed triage-summary-text">${sm[1]}</p>`; 
                                }
                            }
                            
                            const ac = triageText.match(/"next_action":\s*"([^"]+)"/);
                            if (ac) { 
                                const container = itemEl.querySelector('.triage-next-action-container');
                                if(container) { 
                                    container.innerHTML = `<div class="bg-indigo-600 text-white px-5 py-4 rounded-2xl shadow-xl shadow-indigo-100 inline-block font-bold triage-next-action">→ ${ac[1]}</div>`; 
                                }
                            }

                            const urg = triageText.match(/"urgency":\s*"(High|Medium|Low)"/);
                            if (urg) {
                                const u = urg[1]; if(badge) badge.innerText = u + ' Priority';
                                const c = getUrgencyClass(u).split(' ');
                                if(badge) badge.className = `text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full border border-current status-badge ${c[0]} ${c[1]}`;
                                if(iconBg) iconBg.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 triage-icon ${c[0]}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>`;
                                if(iconBg) iconBg.className = `w-14 h-14 rounded-2xl flex items-center justify-center shrink-0 transition-colors duration-500 triage-icon-bg ${c[0].replace('text-', 'bg-').replace('700', '100')}`;
                                itemEl.classList.add(`urgency-${u}`);
                            }
                        } else if (ev === 'complete') {
                            processedData.push(data);
                            localStorage.setItem('clinic_voicemails', JSON.stringify(processedData));
                            setTimeout(renderDashboard, 1500);
                        }
                    }
                }
            } catch (err) { alert('Failed: ' + err.message); }
        }

        function getUrgencyClass(u) {
            if (u === 'High') return 'text-red-700 border-red-200';
            if (u === 'Medium') return 'text-amber-700 border-amber-200';
            return 'text-emerald-700 border-emerald-200';
        }

        async function refreshFileList() {
            console.log("Fetching file list...");
            const fd = new FormData(); fd.append('action', 'list_audio');
            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
                const text = await res.text();
                try {
                    filesOnDisk = JSON.parse(text);
                    console.log("Files found on disk:", filesOnDisk);
                    renderDashboard();
                } catch (e) {
                    console.error("Failed to parse JSON response:", text);
                }
            } catch (err) { 
                console.error("Refresh failed:", err); 
            }
        }

        function updateStatus(id, s) { processedData = processedData.map(v => v.id === id ? {...v, status: s} : v); localStorage.setItem('clinic_voicemails', JSON.stringify(processedData)); renderDashboard(); }
        function deleteVoicemail(id) { if (confirm('Delete record?')) { processedData = processedData.filter(v => v.id !== id); localStorage.setItem('clinic_voicemails', JSON.stringify(processedData)); renderDashboard(); } }

        document.addEventListener('DOMContentLoaded', refreshFileList);
    </script>
</body>
</html>
