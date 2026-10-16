const root = document.getElementById('scan-cards');
if (root) {
    const button = document.getElementById('scan-capture');
    const video = document.getElementById('scan-video');
    const canvas = document.getElementById('scan-canvas');
    const output = document.getElementById('scan-results');
    let stream;
    let worker;

    const message = (text, error = false) => {
        output.replaceChildren();
        const p = document.createElement('p');
        p.className = error ? 'alert alert-danger' : 'muted small-note';
        p.textContent = text;
        output.append(p);
    };

    const addCard = async (id, name, cardButton) => {
        cardButton.disabled = true;
        const body = new FormData();
        body.append('scryfall_id', id); body.append('quantity', '1'); body.append('board', 'main');
        try {
            const response = await fetch(root.dataset.storeEndpoint, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body });
            if (!response.ok) throw new Error('Não foi possível adicionar; verifique o limite de cartas do deck.');
            cardButton.textContent = 'Adicionada';
            message(`${name} adicionada. Atualize a página para ver o deck atualizado.`);
        } catch (error) { cardButton.disabled = false; message(error.message, true); }
    };

    const findCandidates = async recognizedText => {
        const terms = [...new Set(recognizedText.split(/\r?\n/).map(line => line.replace(/[^\p{L}\p{N}'’:, -]/gu, ' ').trim()).filter(line => line.length >= 4))].slice(0, 6);
        const matches = new Map();
        for (const term of terms) {
            const response = await fetch(`/cards/search?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) continue;
            const data = await response.json();
            for (const card of data.data || []) matches.set(card.id, card);
            if (matches.size >= 8) break;
        }
        return [...matches.values()].slice(0, 8);
    };

    button.addEventListener('click', async () => {
        if (!stream) {
            if (!navigator.mediaDevices?.getUserMedia) { message('Câmera indisponível. Abra o ManaLeague por HTTPS e permita o acesso à câmera.', true); return; }
            button.disabled = true;
            try {
                message('Abrindo câmera…');
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1600 } }, audio: false });
                video.srcObject = stream; video.hidden = false; await video.play();
                button.dataset.capturing = 'true'; button.textContent = 'Capturar carta'; button.disabled = false;
                message('Enquadre uma carta por vez, com o nome visível e foco nítido; depois toque em Capturar carta.');
            } catch (error) {
                stopCamera();
                message(error.message || 'Permissão da câmera negada ou câmera indisponível.', true);
            }
            return;
        }

        button.disabled = true;
        try {
            if (!video.videoWidth || !video.videoHeight) throw new Error('A câmera não retornou uma imagem. Tente novamente.');
            const sourceWidth = video.videoWidth;
            const sourceHeight = video.videoHeight;
            const targetRatio = 5 / 7;
            let sourceX = 0;
            let sourceY = 0;
            let cropWidth = sourceWidth;
            let cropHeight = sourceHeight;
            if (sourceWidth / sourceHeight > targetRatio) {
                cropWidth = Math.round(sourceHeight * targetRatio);
                sourceX = Math.round((sourceWidth - cropWidth) / 2);
            } else {
                cropHeight = Math.round(sourceWidth / targetRatio);
                sourceY = Math.round((sourceHeight - cropHeight) / 2);
            }
            canvas.width = 720;
            canvas.height = 1000;
            canvas.getContext('2d').drawImage(video, sourceX, sourceY, cropWidth, cropHeight, 0, 0, canvas.width, canvas.height);
            const title = document.createElement('canvas');
            title.width = 720;
            title.height = 180;
            title.getContext('2d').drawImage(canvas, 0, 25, 720, 180, 0, 0, 720, 180);
            const blob = await new Promise(resolve => title.toBlob(resolve, 'image/png'));
            stopCamera();
            if (!blob) throw new Error('Não consegui capturar a foto.');
            message('Carregando OCR local (primeira leitura pode demorar)…');
            worker ??= await Tesseract.createWorker('eng+por', 1, {
                workerPath: root.dataset.worker,
                corePath: root.dataset.core,
                langPath: root.dataset.language,
                workerBlobURL: false,
                logger: event => { if (event.status === 'recognizing text') message(`Lendo o nome… ${Math.round((event.progress || 0) * 100)}%`); },
            });
            message('Reconhecendo texto neste aparelho…');
            const { data } = await worker.recognize(blob);
            const candidates = await findCandidates(data.text);
            output.replaceChildren();
            if (!candidates.length) { message(`Não achei correspondência. Texto lido: ${data.text.trim().slice(0, 180) || 'nenhum'}. Tente ajustar o enquadramento ou use a busca manual.`, true); return; }
            const heading = document.createElement('p'); heading.className = 'muted small-note'; heading.textContent = `Confira o resultado antes de adicionar. Leitura: ${data.text.trim().slice(0, 120)}`; output.append(heading);
            candidates.forEach(card => {
                const row = document.createElement('div'); row.className = 'scan-result-row';
                const name = document.createElement('strong'); name.textContent = card.name;
                const confirm = document.createElement('button'); confirm.type = 'button'; confirm.className = 'btn btn-small btn-secondary'; confirm.textContent = 'Adicionar';
                confirm.addEventListener('click', () => addCard(card.id, card.name, confirm)); row.append(name, confirm); output.append(row);
            });
        } catch (error) {
            stopCamera();
            message(error.message || 'Falha ao reconhecer a carta.', true);
        }
    });

}

