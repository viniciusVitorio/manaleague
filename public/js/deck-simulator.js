/* Local-only simulation. No deck mutations or network calls. */
(function (root) {
    'use strict';
    class DeckSimulator {
        constructor(cards, random = Math.random, freeMulligan = false) {
            this.cards = cards.flatMap(card => Array.from({length: Math.max(0, Math.min(99, Number(card.quantity) || 0))}, () => ({...card})));
            this.random = random;
            this.freeMulligan = freeMulligan;
            this.reset();
        }
        shuffle() {
            const cards = this.cards.slice();
            for (let i = cards.length - 1; i > 0; i--) {
                const j = Math.floor(this.random() * (i + 1));
                [cards[i], cards[j]] = [cards[j], cards[i]];
            }
            return cards;
        }
        deal() { this.library = this.shuffle(); this.hand = this.library.splice(0, 7); }
        reset() { this.mulligans = 0; this.kept = false; this.draws = 0; this.deal(); }
        get bottomCount() { return Math.max(0, this.mulligans - (this.freeMulligan ? 1 : 0)); }
        mulligan() {
            if (this.kept || this.bottomCount >= Math.min(7, this.cards.length)) throw new Error('Não é possível fazer outro mulligan.');
            this.mulligans++; this.deal();
        }
        keep(indices) {
            if (this.kept || indices.length !== this.bottomCount || new Set(indices).size !== indices.length || indices.some(i => !Number.isInteger(i) || i < 0 || i >= this.hand.length)) throw new Error('Selecione exatamente as cartas que devem ir ao fundo.');
            const selected = new Set(indices);
            // Click order is the order from top to bottom of the returned packet.
            this.library.push(...indices.map(i => this.hand[i]));
            this.hand = this.hand.filter((_, i) => !selected.has(i)); this.kept = true;
        }
        draw() {
            if (!this.kept) throw new Error('Mantenha a mão antes de comprar.');
            const card = this.library.shift() || null;
            if (card) { this.hand.push(card); this.draws++; }
            return card;
        }
        statistics(samples = 1000) {
            samples = Math.max(1, Math.min(10000, Math.floor(samples) || 1000));
            const distribution = Array(8).fill(0); let total = 0;
            for (let i = 0; i < samples; i++) {
                const lands = this.shuffle().slice(0,7).filter(c => c.land).length;
                distribution[lands]++; total += lands;
            }
            return {samples, distribution, averageLands: total / samples};
        }
    }
    root.DeckSimulator = DeckSimulator;
    if (typeof document === 'undefined') return;
    const element = document.querySelector('[data-simulator]');
    if (!element) return;
    const cards = JSON.parse(element.dataset.cards);
    let sim = new DeckSimulator(cards), selected = [];
    const hand = element.querySelector('[data-hand]'), status = element.querySelector('[data-status]');
    const button = name => element.querySelector(`[data-action="${name}"]`);
    function render() {
        hand.replaceChildren();
        sim.hand.forEach((card, i) => {
            const node = document.createElement('button'); node.type = 'button'; node.className = 'sim-card';
            node.setAttribute('aria-pressed', String(selected.includes(i))); node.disabled = sim.kept;
            if (card.image && /^https:\/\/cards\.scryfall\.io\//.test(card.image)) { const image = document.createElement('img'); image.src = card.image; image.alt = ''; node.append(image); }
            const label = document.createElement('span'); label.textContent = card.name; node.append(label);
            node.addEventListener('click', () => { selected = selected.includes(i) ? selected.filter(n => n !== i) : [...selected, i]; render(); });
            hand.append(node);
        });
        status.textContent = `${sim.hand.length} na mão · ${sim.library.length} no grimório · ${sim.mulligans} mulligans · ${sim.draws} compras. ${sim.kept ? 'Mão mantida.' : `Selecione ${sim.bottomCount} carta(s) para o fundo (${selected.length} selecionada(s)).`}`;
        button('mulligan').disabled = sim.kept || sim.bottomCount >= Math.min(7,sim.cards.length);
        button('keep').disabled = sim.kept || selected.length !== sim.bottomCount;
        button('draw').disabled = !sim.kept || !sim.library.length;
    }
    ['mulligan','keep','draw','reset'].forEach(action => button(action).addEventListener('click', () => {
        try { action === 'keep' ? sim.keep(selected) : sim[action](); selected = []; render(); }
        catch (error) { status.textContent = error.message; }
    }));
    element.querySelector('[data-free-mulligan]').addEventListener('change', event => { sim = new DeckSimulator(cards, Math.random, event.target.checked); selected = []; render(); });
    button('stats').addEventListener('click', () => {
        const stats = sim.statistics();
        const target = element.querySelector('[data-statistics]'); target.replaceChildren();
        const summary = document.createElement('p'); summary.textContent = `${stats.samples} mãos iniciais independentes, sem mulligan. Média: ${stats.averageLands.toFixed(2)} terrenos.`; target.append(summary);
        stats.distribution.forEach((count, lands) => { const row = document.createElement('p'); row.textContent = `${lands} terrenos: ${(100*count/stats.samples).toFixed(1)}% (${count} mãos)`; target.append(row); });
    });
    render();
})(globalThis);
