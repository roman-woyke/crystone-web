<?php

require_once __DIR__ . "/includes/session.php";
require_once __DIR__ . "/includes/scoring.php";

$userId = $_SESSION["user_id"];

// ── Handle application creation ───────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $companyName = trim($_POST["company_name"] ?? "");
    $jobTitle    = trim($_POST["job_title"]    ?? "");
    $jobLink     = trim($_POST["job_link"]     ?? "");
    $location    = trim($_POST["location"]     ?? "");
    $notes       = trim($_POST["notes"]        ?? "");
    $tag         = trim($_POST["tag"]          ?? "");

    if ($companyName === "") {
        die("Company name is required.");
    }

    $jobTitle = $jobTitle === "" ? null : $jobTitle;
    $jobLink  = $jobLink  === "" ? null : $jobLink;
    $location = $location === "" ? null : $location;
    $notes    = $notes    === "" ? null : $notes;

    $allowedTags = ["MAYBE", "PROBABLY", "FOR SURE", "ABSOLUTE CINEMA"];
    $tag         = in_array($tag, $allowedTags, true) ? $tag : null;

    $stmt = $pdo->prepare("
        INSERT INTO applications (
            user_id, company_name, job_title, job_link, location, notes, tag, status
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING')
    ");

    $stmt->execute([$userId, $companyName, $jobTitle, $jobLink, $location, $notes, $tag]);

    $newApplicationId = $pdo->lastInsertId();

    $historyStmt = $pdo->prepare("
        INSERT INTO application_status_history (user_id, application_id, status)
        VALUES (?, ?, 'PENDING')
    ");

    $historyStmt->execute([$userId, $newApplicationId]);

    header("Location: " . BASE_PATH . "/dashboard.php");
    exit;
}

// ── Fetch applications ────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        a.id,
        a.company_name,
        a.job_title,
        a.job_link,
        a.location,
        a.status,
        a.notes,
        a.tag,
        a.created_at,

        " . peakStatusSql() . "

    FROM applications a
    LEFT JOIN application_status_history h
        ON h.application_id = a.id

    WHERE a.user_id = ?

    GROUP BY
        a.id, a.company_name, a.job_title, a.job_link,
        a.location, a.status, a.notes, a.tag, a.created_at

    ORDER BY a.created_at DESC
");

$stmt->execute([$userId]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

function tagBadge(?string $tag): string {
    if (empty($tag)) return "";
    $slug  = strtolower(str_replace(' ', '-', $tag));
    $label = htmlspecialchars($tag);
    return '<span class="tag-badge tag-badge-' . $slug . '">' . $label . '</span>';
}

require_once __DIR__ . "/includes/header.php";
?>

    <h1>Dashboard</h1>

    <p>
        Logged in as:
        <strong><?= htmlspecialchars($_SESSION["username"]) ?></strong>
    </p>

    <p>
        <a href="<?= BASE_PATH ?>/leaderboard.php">View leaderboard</a> |
        <a href="<?= BASE_PATH ?>/logout.php">Logout</a>
    </p>

    <h2>Add application</h2>

    <form action="<?= BASE_PATH ?>/dashboard.php" method="POST">
        <div>
            <label>Company name *</label>
            <input type="text" name="company_name" required>
        </div>
        <div>
            <label>Job title</label>
            <input type="text" name="job_title">
        </div>
        <div>
            <label>Job link</label>
            <input type="url" name="job_link">
        </div>
        <div>
            <label>Location</label>
            <input type="text" name="location">
        </div>
        <div>
            <label>Notes</label>
            <textarea name="notes"></textarea>
        </div>
        <div>
            <label>Tag</label>
            <select name="tag">
                <option value="">— none —</option>
                <option value="MAYBE">Maybe</option>
                <option value="PROBABLY">Probably</option>
                <option value="FOR SURE">For Sure</option>
                <option value="ABSOLUTE CINEMA">Absolute Cinema</option>
            </select>
        </div>
        <button type="submit">Add application</button>
    </form>

    <h2>My applications</h2>

    <?php if (count($applications) === 0): ?>
        <p>No applications yet.</p>
    <?php else: ?>
        <table border="1" cellpadding="8" width="100%">
            <thead>
                <tr>
                    <th>Tag</th>
                    <th>Company</th>
                    <th>Job</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Link</th>
                    <th>Notes</th>
                    <th>Created</th>
                    <th>Delete</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $app): ?>
                    <?php $id = (int) $app["id"]; ?>
                    <tr id="app-<?= $id ?>">

                        <!-- Tag -->
                        <td style="vertical-align:top;">
                            <div style="display:flex; flex-direction:column; gap:4px;">
                            <select class="tag-select" data-id="<?= $id ?>" style="width:100%; min-width:67px;">
                                <option value="">— none —</option>
                                <?php foreach (["MAYBE", "PROBABLY", "FOR SURE", "ABSOLUTE CINEMA"] as $t): ?>
                                    <option value="<?= $t ?>" <?= $app["tag"] === $t ? "selected" : "" ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="save-btn" data-id="<?= $id ?>" data-field="tag" style="display:none;">Save</button>
                            </div>
                        </td>

                        <!-- Company -->
                        <td><?= htmlspecialchars($app["company_name"]) ?></td>

                        <!-- Job -->
                        <td><?= htmlspecialchars($app["job_title"] ?? "") ?></td>

                        <!-- Location -->
                        <td><?= htmlspecialchars($app["location"] ?? "") ?></td>

                        <!-- Status -->
                        <td style="min-width:140px;">
                            <?php if (!empty($app["peak_status"]) && $app["peak_status"] !== $app["status"] && in_array($app["peak_status"], ["INTERVIEW", "OFFER"])): ?>
                                <div style="display:flex; flex-direction:column; align-items:center; gap:2px; margin-bottom:6px;">
                                    <strong><?= htmlspecialchars($app["peak_status"]) ?></strong>
                                    <span style="opacity:0.4; font-size:0.8em;">↓</span>
                                </div>
                            <?php endif; ?>
                            <select class="status-select" data-id="<?= $id ?>" style="width:100%;">
                                <?php foreach (["PENDING", "REJECTED", "GHOSTED", "INTERVIEW", "OFFER"] as $s): ?>
                                    <option value="<?= $s ?>" <?= $app["status"] === $s ? "selected" : "" ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="save-btn" data-id="<?= $id ?>" data-field="status" style="display:none; margin-top:4px; width:100%;">Save</button>
                        </td>

                        <!-- Link -->
                        <td style="vertical-align:top;">
                            <div style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">
                            <?php if (!empty($app["job_link"])): ?>
                                <a href="<?= htmlspecialchars($app["job_link"]) ?>" target="_blank" class="link-display-<?= $id ?>">Open</a>
                            <?php else: ?>
                                <span class="link-display-<?= $id ?>" style="color:#6b7280;">—</span>
                            <?php endif; ?>
                            <button class="edit-link-btn" data-id="<?= $id ?>" data-current="<?= htmlspecialchars($app['job_link'] ?? '') ?>">✏️</button>
                            </div>
                        </td>

                        <!-- Notes -->
                        <td>
                            <span class="notes-display-<?= $id ?>"><?= htmlspecialchars(mb_strimwidth($app["notes"] ?? "", 0, 40, "…")) ?></span>
                            <button class="edit-notes-btn" data-id="<?= $id ?>" data-current="<?= htmlspecialchars($app['notes'] ?? '') ?>" style="margin-left:4px;">✏️</button>
                        </td>

                        <!-- Created -->
                        <td><?= htmlspecialchars($app["created_at"]) ?></td>

                        <!-- Delete -->
                        <td>
                            <button class="delete-btn" data-id="<?= $id ?>">Delete</button>
                        </td>

                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- ── Modal overlay ──────────────────────────────────────────────── -->
    <div id="edit-modal" style="
        display:none;
        position:fixed; inset:0;
        background:rgba(0,0,0,0.6);
        z-index:1000;
        align-items:center;
        justify-content:center;
    ">
        <div style="
            background:#1f2937;
            border:1px solid #374151;
            border-radius:10px;
            padding:28px;
            width:480px;
            max-width:90vw;
        ">
            <h3 id="modal-title" style="margin-top:0;"></h3>
            <textarea id="modal-textarea" style="display:none; width:100%; min-height:120px; margin-bottom:12px;"></textarea>
            <input id="modal-input" type="url" style="display:none; width:100%; margin-bottom:12px;">
            <div style="display:flex; gap:8px; justify-content:flex-end;">
                <button id="modal-cancel">Cancel</button>
                <button id="modal-save" style="background:#2563eb;">Save</button>
            </div>
        </div>
    </div>

<script>
const BASE_PATH = "<?= BASE_PATH ?>";

// ── Show save button when tag or status changes ───────────────────────

document.querySelectorAll(".tag-select, .status-select").forEach(select => {
    select.addEventListener("change", () => {
        const id    = select.dataset.id;
        const field = select.classList.contains("tag-select") ? "tag" : "status";
        const btn   = document.querySelector(`.save-btn[data-id="${id}"][data-field="${field}"]`);
        if (btn) btn.style.display = "inline-block";
    });
});

// ── Save handler for tag / status ─────────────────────────────────────

document.querySelectorAll(".save-btn").forEach(btn => {
    btn.addEventListener("click", () => {
        const id    = btn.dataset.id;
        const field = btn.dataset.field;

        let value;
        if (field === "tag") {
            value = document.querySelector(`.tag-select[data-id="${id}"]`).value;
        } else if (field === "status") {
            value = document.querySelector(`.status-select[data-id="${id}"]`).value;
        }

        const body = new FormData();
        body.append("application_id", id);
        body.append(field, value);

        fetch(BASE_PATH + "/api/patch-application.php", { method: "POST", body })
            .then(r => {
                if (!r.ok) throw new Error("Save failed.");
                btn.style.display = "none";
            })
            .catch(err => alert(err.message));
    });
});

// ── Modal logic ───────────────────────────────────────────────────────

const modal        = document.getElementById("edit-modal");
const modalTitle   = document.getElementById("modal-title");
const modalSave    = document.getElementById("modal-save");
const modalCancel  = document.getElementById("modal-cancel");
const modalArea    = document.getElementById("modal-textarea");
const modalInput   = document.getElementById("modal-input");

let modalField = null;
let modalId    = null;

function openModal(id, field, title, currentValue) {
    modalId    = id;
    modalField = field;
    modalTitle.textContent = title;

    if (field === "notes") {
        modalArea.style.display  = "block";
        modalInput.style.display = "none";
        modalArea.value = currentValue;
    } else {
        modalInput.style.display = "block";
        modalArea.style.display  = "none";
        modalInput.value = currentValue;
    }

    modal.style.display = "flex";
    (field === "notes" ? modalArea : modalInput).focus();
}

function closeModal() {
    modal.style.display = "none";
    modalField = null;
    modalId    = null;
}

modalCancel.addEventListener("click", closeModal);

modal.addEventListener("click", e => {
    if (e.target === modal) closeModal();
});

modalSave.addEventListener("click", () => {
    const value = modalField === "notes" ? modalArea.value : modalInput.value;
    const body  = new FormData();
    body.append("application_id", modalId);
    body.append(modalField, value);

    fetch(BASE_PATH + "/api/patch-application.php", { method: "POST", body })
        .then(r => {
            if (!r.ok) throw new Error("Save failed.");

            if (modalField === "job_link") {
                const displayEl = document.querySelector(`.link-display-${modalId}`);
                if (value) {
                    const a = document.createElement("a");
                    a.href        = value;
                    a.target      = "_blank";
                    a.textContent = "Open";
                    a.className   = `link-display-${modalId}`;
                    displayEl.replaceWith(a);
                    document.querySelector(`.edit-link-btn[data-id="${modalId}"]`).dataset.current = value;
                } else {
                    displayEl.textContent = "—";
                    displayEl.removeAttribute("href");
                }
            }

            if (modalField === "notes") {
                const shortened = value.length > 40 ? value.slice(0, 40) + "…" : value;
                document.querySelector(`.notes-display-${modalId}`).textContent = shortened;
                document.querySelector(`.edit-notes-btn[data-id="${modalId}"]`).dataset.current = value;
            }

            closeModal();
        })
        .catch(err => alert(err.message));
});

// ── Open modal for link ───────────────────────────────────────────────

document.querySelectorAll(".edit-link-btn").forEach(btn => {
    btn.addEventListener("click", () => {
        openModal(btn.dataset.id, "job_link", "Edit link", btn.dataset.current);
    });
});

// ── Open modal for notes ──────────────────────────────────────────────

document.querySelectorAll(".edit-notes-btn").forEach(btn => {
    btn.addEventListener("click", () => {
        openModal(btn.dataset.id, "notes", "Edit notes", btn.dataset.current);
    });
});

// ── Delete handler ────────────────────────────────────────────────────

document.querySelectorAll(".delete-btn").forEach(btn => {
    btn.addEventListener("click", () => {
        if (!confirm("Delete this application? This cannot be undone.")) return;

        const id   = btn.dataset.id;
        const body = new FormData();
        body.append("application_id", id);

        fetch(BASE_PATH + "/api/delete-application.php", { method: "POST", body })
            .then(r => {
                if (!r.ok) throw new Error("Delete failed.");
                document.getElementById(`app-${id}`).remove();
            })
            .catch(err => alert(err.message));
    });
});
</script>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
