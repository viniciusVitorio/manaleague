(() => {
    'use strict';
    const data = JSON.parse(document.getElementById('achievement-data').textContent);
    const canvas = document.getElementById('achievement-card');
    const ctx = canvas.getContext('2d');
    const feedback = document.getElementById('share-feedback');
    ctx.fillStyle = '#10121d'; ctx.fillRect(0, 0, 1200, 630);
    ctx.strokeStyle = '#8b5cf6'; ctx.lineWidth = 4; ctx.strokeRect(30, 30, 1140, 570);
    ctx.fillStyle = '#b9a4ff'; ctx.font = 'bold 28px sans-serif'; ctx.fillText('MANALEAGUE · CONQUISTA DESBLOQUEADA', 80, 110);
    ctx.fillStyle = '#ffffff'; ctx.font = 'bold 64px sans-serif';
    ctx.fillText(data.name, 80, 260, 1040);
    ctx.font = '30px sans-serif'; ctx.fillStyle = '#ddd9ed';
    let line = '', y = 350;
    for (const word of data.description.split(/\s+/)) {
        const next = line ? `${line} ${word}` : word;
        if (ctx.measureText(next).width > 1020 && line) { ctx.fillText(line, 80, y); y += 44; line = word; }
        else line = next;
    }
    ctx.fillText(line, 80, y);
    ctx.font = '24px sans-serif'; ctx.fillStyle = '#b9a4ff'; ctx.fillText('Cada mesa conta uma história.', 80, 550);
    const blob = () => new Promise((resolve, reject) => canvas.toBlob(value => value ? resolve(value) : reject(new Error('PNG indisponível')), 'image/png'));
    function download(value) {
        const url = URL.createObjectURL(value), anchor = document.createElement('a');
        anchor.href = url; anchor.download = 'manaleague-conquista.png'; document.body.append(anchor); anchor.click(); anchor.remove();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
    }
    document.getElementById('download-png').addEventListener('click', async () => {
        try { download(await blob()); feedback.textContent = 'PNG gerado para download.'; }
        catch { feedback.textContent = 'Não foi possível gerar a imagem. Tente outro navegador.'; }
    });
    document.getElementById('share-png').addEventListener('click', async () => {
        try {
            const value = await blob();
            const file = new File([value], 'manaleague-conquista.png', {type: 'image/png'});
            if (navigator.canShare?.({files: [file]}) && navigator.share) {
                await navigator.share({files: [file], title: 'Conquista ManaLeague'});
                feedback.textContent = 'Imagem compartilhada.';
            } else { download(value); feedback.textContent = 'Compartilhamento não disponível. PNG baixado para enviar manualmente.'; }
        } catch (error) { feedback.textContent = error.name === 'AbortError' ? 'Compartilhamento cancelado.' : 'Falha ao compartilhar. Use Baixar PNG.'; }
    });
})();
