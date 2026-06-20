<?php

require_once __DIR__ . "/includes/session.php";
require_once __DIR__ . "/includes/typing-sentences.php";

$pageTitle = "Typing Battle";
require_once __DIR__ . "/includes/header.php";
?>

<style>
.typing-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 320px;
    gap: 24px;
    align-items: start;
}

@media (max-width: 1000px) {
    .typing-layout {
        grid-template-columns: 1fr;
    }
}

.typing-hud {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}

.typing-card {
    position: relative;
    padding: 28px;
    cursor: text;
}

.sentence-display {
    min-height: 110px;

    font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
    font-size: 1.25rem;
    line-height: 1.9;
    letter-spacing: 0.01em;
    word-break: break-word;
    white-space: pre-wrap;
}

.sentence-display .c-pending { color: var(--text-3); }
.sentence-display .c-ok      { color: var(--text-1); }

.sentence-display .c-bad {
    color: var(--danger);
    background: rgba(248, 113, 113, 0.15);
    border-radius: 3px;
}

.sentence-display .c-cur {
    position: relative;
}

.sentence-display .c-cur::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    bottom: 2px;

    height: 2px;
    border-radius: 2px;
    background: var(--grad-accent);

    animation: caret-blink 1s steps(1, end) infinite;
}

@media (prefers-reduced-motion: reduce) {
    .sentence-display .c-cur::after { animation: none; }
}

@keyframes caret-blink {
    50% { opacity: 0; }
}

.next-sentence {
    margin-top: 14px;

    font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
    font-size: 0.9rem;
    color: var(--text-3);
    opacity: 0.7;
}

#typing-input {
    position: absolute;
    opacity: 0;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: 0;
    border: none;
}

.game-overlay {
    position: absolute;
    inset: 0;
    z-index: 5;

    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;

    background: rgba(10, 10, 20, 0.78);
    border-radius: var(--radius-lg);
    text-align: center;
    padding: 24px;
}

.game-overlay .overlay-title {
    font-family: var(--font-display);
    font-size: 1.5rem;
    font-weight: 700;
}

.game-overlay .overlay-sub {
    margin: 0;
    max-width: 380px;
    color: var(--text-2);
    font-size: 0.92rem;
}

.game-overlay .countdown-num {
    font-family: var(--font-display);
    font-size: 4.5rem;
    font-weight: 700;
}

.game-overlay button {
    width: auto;
}

.results-card {
    margin-top: 18px;
    padding: 28px;
    text-align: center;
}

.results-card .result-wpm {
    font-family: var(--font-display);
    font-size: 3.6rem;
    font-weight: 700;
    line-height: 1.1;
}

.results-card .result-unit {
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.09em;
    text-transform: uppercase;
    color: var(--text-3);
}

.results-card .result-meta {
    margin: 10px 0 18px;
    color: var(--text-2);
}

.results-card .pb-badge {
    display: inline-block;
    margin-bottom: 14px;
    padding: 5px 16px;

    font-size: 0.82rem;
    font-weight: 700;

    color: #fff;
    background: var(--grad-accent);
    border-radius: var(--radius-full);
    box-shadow: var(--glow-violet);
}

.results-card button {
    width: auto;
}

.highscore-panel {
    padding: 22px;
}

.highscore-panel h2 {
    font-size: 1.15rem;
    margin-bottom: 14px;
}

.highscore-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.highscore-list li {
    display: flex;
    align-items: center;
    gap: 12px;

    padding: 10px 12px;

    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-md);
}

.highscore-list li.me {
    border-color: rgba(139, 92, 246, 0.45);
    background: var(--grad-accent-soft);
}

.highscore-list .hs-rank {
    flex-shrink: 0;
    width: 26px;
    text-align: center;
    font-weight: 700;
    color: var(--text-3);
}

.highscore-list .hs-name {
    flex: 1;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.highscore-list .hs-wpm {
    font-family: var(--font-display);
    font-weight: 700;
}

.highscore-list .hs-sub {
    font-size: 0.74rem;
    color: var(--text-3);
}
</style>

    <h1 class="page-heading">Typing <span class="gradient-text">Battle</span></h1>
    <p class="muted">60 seconds. Cover-letter classics. Highest WPM takes the crown — only correctly typed characters count.</p>

    <div class="typing-layout">

        <div>
            <div class="typing-hud">
                <div class="stat-card glass-card">
                    <span class="stat-value" id="hud-timer">60</span>
                    <span class="stat-label">Seconds</span>
                </div>
                <div class="stat-card glass-card">
                    <span class="stat-value gradient-text" id="hud-wpm">0</span>
                    <span class="stat-label">WPM</span>
                </div>
                <div class="stat-card glass-card">
                    <span class="stat-value" id="hud-accuracy">100%</span>
                    <span class="stat-label">Accuracy</span>
                </div>
            </div>

            <div class="typing-card glass-card" id="typing-card">
                <div class="sentence-display" id="sentence-display"></div>
                <div class="next-sentence" id="next-sentence"></div>

                <input
                    id="typing-input"
                    type="text"
                    autocomplete="off"
                    autocapitalize="off"
                    autocorrect="off"
                    spellcheck="false"
                >

                <div class="game-overlay" id="game-overlay">
                    <div class="overlay-title">Ready to type?</div>
                    <p class="overlay-sub">
                        Type the sentences as fast and as accurately as you can.
                        Backspace works within the current sentence.
                    </p>
                    <button type="button" class="btn-primary" id="start-btn">Start 60-second round</button>
                </div>
            </div>

            <div class="results-card glass-card" id="results-card" style="display:none;">
                <div id="pb-badge" class="pb-badge" style="display:none;">New personal best!</div>
                <div class="result-wpm gradient-text" id="result-wpm">0</div>
                <div class="result-unit">Words per minute</div>
                <p class="result-meta" id="result-meta"></p>
                <button type="button" class="btn-primary" id="retry-btn">Play again</button>
            </div>
        </div>

        <aside class="highscore-panel glass-card">
            <h2>🏆 Highscores</h2>
            <ul class="highscore-list" id="highscore-list">
                <li><span class="hs-name muted">Loading…</span></li>
            </ul>
        </aside>

    </div>

<script>
(function () {
    const BASE_PATH = "<?= BASE_PATH ?>";
    const MY_USERNAME = <?= json_encode($_SESSION["username"] ?? "") ?>;
    const SENTENCES = <?= json_encode($TYPING_SENTENCES) ?>;
    const ROUND_MS = 60000;

    const display    = document.getElementById("sentence-display");
    const nextEl     = document.getElementById("next-sentence");
    const input      = document.getElementById("typing-input");
    const overlay    = document.getElementById("game-overlay");
    const card       = document.getElementById("typing-card");
    const startBtn   = document.getElementById("start-btn");
    const retryBtn   = document.getElementById("retry-btn");
    const resultsEl  = document.getElementById("results-card");
    const hudTimer   = document.getElementById("hud-timer");
    const hudWpm     = document.getElementById("hud-wpm");
    const hudAcc     = document.getElementById("hud-accuracy");

    let state = "idle"; // idle | countdown | running | done
    let queue = [];
    let sentence = "";
    let prevLen = 0;

    let typedTotal = 0;       // every character keystroke
    let correctCommitted = 0; // correct chars in completed sentences
    let startTime = null;
    let tickInterval = null;
    let endTimeout = null;

    function shuffled(arr) {
        const a = arr.slice();
        for (let i = a.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [a[i], a[j]] = [a[j], a[i]];
        }
        return a;
    }

    function nextSentence() {
        if (queue.length === 0) queue = shuffled(SENTENCES);
        sentence = queue.shift();
        prevLen = 0;
        input.value = "";
        renderSentence("");
        nextEl.textContent = queue.length > 0 ? "Next: " + queue[0] : "";
    }

    function renderSentence(typed) {
        let html = "";
        for (let i = 0; i < sentence.length; i++) {
            const ch = sentence[i];
            let cls;
            if (i < typed.length) {
                cls = typed[i] === ch ? "c-ok" : "c-bad";
            } else if (i === typed.length) {
                cls = "c-pending c-cur";
            } else {
                cls = "c-pending";
            }
            html += `<span class="${cls}">${escapeHtml(ch)}</span>`;
        }
        display.innerHTML = html;
    }

    function correctInCurrent(typed) {
        let n = 0;
        const limit = Math.min(typed.length, sentence.length);
        for (let i = 0; i < limit; i++) {
            if (typed[i] === sentence[i]) n++;
        }
        return n;
    }

    function liveStats() {
        const correctSoFar = correctCommitted + correctInCurrent(input.value);
        const elapsedMin = Math.max((Date.now() - startTime) / 60000, 1 / 60);
        const wpm = Math.round((correctSoFar / 5) / elapsedMin);
        const acc = typedTotal > 0 ? Math.round(correctSoFar / typedTotal * 100) : 100;
        return { correctSoFar, wpm, acc };
    }

    function updateHud() {
        const remaining = Math.max(0, Math.ceil((startTime + ROUND_MS - Date.now()) / 1000));
        hudTimer.textContent = remaining;

        const { wpm, acc } = liveStats();
        hudWpm.textContent = wpm;
        hudAcc.textContent = acc + "%";
    }

    function startCountdown() {
        state = "countdown";
        resultsEl.style.display = "none";

        typedTotal = 0;
        correctCommitted = 0;
        queue = shuffled(SENTENCES);
        nextSentence();

        let n = 3;
        overlay.innerHTML = `<div class="countdown-num gradient-text">${n}</div>`;
        overlay.style.display = "flex";

        const cd = setInterval(() => {
            n--;
            if (n > 0) {
                overlay.innerHTML = `<div class="countdown-num gradient-text">${n}</div>`;
                return;
            }
            clearInterval(cd);
            startRound();
        }, 800);
    }

    function startRound() {
        state = "running";
        overlay.style.display = "none";

        startTime = Date.now();
        input.focus();
        updateHud();

        tickInterval = setInterval(updateHud, 250);
        endTimeout = setTimeout(finishRound, ROUND_MS);
    }

    function finishRound() {
        state = "done";
        clearInterval(tickInterval);
        clearTimeout(endTimeout);

        const durationMs = Date.now() - startTime;
        correctCommitted += correctInCurrent(input.value);
        input.blur();

        const correct = correctCommitted;
        const typed = typedTotal;
        const acc = typed > 0 ? Math.round(correct / typed * 100) : 100;
        const finalWpm = Math.round((correct / 5) / (durationMs / 60000));

        overlay.innerHTML = `
            <div class="overlay-title">Time!</div>
            <button type="button" class="btn-primary" id="overlay-retry">Play again</button>
        `;
        overlay.style.display = "flex";
        document.getElementById("overlay-retry").addEventListener("click", startCountdown);

        document.getElementById("pb-badge").style.display = "none";
        document.getElementById("result-meta").textContent =
            `${acc}% accuracy · ${correct} correct characters`;
        resultsEl.style.display = "block";

        const wpmEl = document.getElementById("result-wpm");
        if (typeof window.countUp === "function") {
            window.countUp(wpmEl, finalWpm, 900);
        } else {
            wpmEl.textContent = finalWpm;
        }

        if (typed === 0) return; // nothing to submit

        const body = new FormData();
        body.append("correct_chars", correct);
        body.append("typed_chars", typed);
        body.append("duration_ms", durationMs);

        fetch(BASE_PATH + "/api/submit-typing-score.php", { method: "POST", body })
            .then(r => {
                if (!r.ok) throw new Error("Score submission failed.");
                return r.json();
            })
            .then(result => {
                if (result.personal_best) {
                    document.getElementById("pb-badge").style.display = "inline-block";
                }
                loadHighscores();
            })
            .catch(() => {
                document.getElementById("result-meta").textContent +=
                    " · (score could not be saved)";
            });
    }

    input.addEventListener("input", () => {
        if (state !== "running") {
            input.value = "";
            return;
        }

        const val = input.value;

        // Count only added characters as keystrokes (backspace shrinks val).
        if (val.length > prevLen) {
            typedTotal += val.length - prevLen;
        }
        prevLen = val.length;

        if (val.length >= sentence.length) {
            correctCommitted += correctInCurrent(val);
            nextSentence();
        } else {
            renderSentence(val);
        }

        updateHud();
    });

    card.addEventListener("click", () => {
        if (state === "running") input.focus();
    });

    startBtn.addEventListener("click", startCountdown);
    retryBtn.addEventListener("click", startCountdown);

    // ── Highscores ───────────────────────────────────────────────────

    function loadHighscores() {
        fetch(BASE_PATH + "/api/get-typing-scores.php")
            .then(r => {
                if (!r.ok) throw new Error("Failed to load highscores.");
                return r.json();
            })
            .then(scores => {
                const list = document.getElementById("highscore-list");

                if (scores.length === 0) {
                    list.innerHTML = `<li><span class="hs-name muted">No runs yet — be the first!</span></li>`;
                    return;
                }

                list.innerHTML = scores.map((s, i) => {
                    const rank = i + 1;
                    const rankHtml = rank <= 3
                        ? `<span class="rank-medal rank-medal-${rank}">${rank}</span>`
                        : rank;
                    const isMe = s.username === MY_USERNAME;

                    return `
                        <li class="${isMe ? "me" : ""}">
                            <span class="hs-rank">${rankHtml}</span>
                            <span class="hs-name">${escapeHtml(s.username)}</span>
                            <span>
                                <span class="hs-wpm">${Math.round(s.wpm)} WPM</span><br>
                                <span class="hs-sub">${Math.round(s.accuracy)}% acc · ${s.runs} run${s.runs == 1 ? "" : "s"}</span>
                            </span>
                        </li>
                    `;
                }).join("");
            })
            .catch(() => {
                document.getElementById("highscore-list").innerHTML =
                    `<li><span class="hs-name muted">Could not load highscores.</span></li>`;
            });
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    }

    loadHighscores();
}());
</script>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
