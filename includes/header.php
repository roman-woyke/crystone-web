<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . " · InternTrack" : "InternTrack" ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">

    <?php $cssPath = __DIR__ . "/../assets/css/style.css"; ?>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css?v=<?= file_exists($cssPath) ? filemtime($cssPath) : time() ?>">

    <?php $jsPath = __DIR__ . "/../assets/js/app.js"; ?>
    <script src="<?= BASE_PATH ?>/assets/js/app.js?v=<?= file_exists($jsPath) ? filemtime($jsPath) : time() ?>" defer></script>
</head>
<body>

<?php
    $loggedIn = isset($_SESSION["user_id"]);
    $currentPage = basename($_SERVER["SCRIPT_NAME"]);

    // Current user's avatar (base64 data URL) + initial for the placeholder.
    $myAvatar  = null;
    $myInitial = strtoupper(mb_substr($_SESSION["username"] ?? "?", 0, 1));
    if ($loggedIn && isset($pdo)) {
        try {
            $st = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
            $st->execute([$_SESSION["user_id"]]);
            $myAvatar = $st->fetchColumn() ?: null;
        } catch (Throwable $e) {
            $myAvatar = null;
        }
    }

    $navLinks = [
        "dashboard.php"   => "Dashboard",
        "leaderboard.php" => "Leaderboard",
        "calendar.php"    => "Calendar",
        "study-counter.php" => "Study Counter",
        "boardle.php"     => "Boardle",
        "projects.php"    => "Projects",
    ];
?>

<nav class="navbar">
    <div class="nav-left">
        <a class="nav-brand" href="<?= BASE_PATH ?><?= $loggedIn ? "/dashboard.php" : "/" ?>">
            <span class="brand-mark">IT</span>
            <span class="gradient-text">InternTrack</span>
        </a>

        <?php if ($loggedIn): ?>
            <div class="nav-links" id="nav-links">
                <?php foreach ($navLinks as $file => $label): ?>
                    <a
                        href="<?= BASE_PATH ?>/<?= $file ?>"
                        <?= $currentPage === $file ? 'class="active"' : "" ?>
                    ><?= $label ?></a>
                <?php endforeach; ?>
                <a class="nav-logout-mobile" href="<?= BASE_PATH ?>/logout.php" id="nav-logout-mobile">Logout</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="nav-right">
        <?php if ($loggedIn): ?>
            <button class="nav-avatar" id="nav-avatar" type="button" title="Profile picture" aria-label="Edit profile picture">
                <?php if ($myAvatar): ?>
                    <img src="<?= htmlspecialchars($myAvatar) ?>" alt="">
                <?php else: ?>
                    <span class="nav-avatar-fallback"><?= htmlspecialchars($myInitial) ?></span>
                <?php endif; ?>
            </button>

            <?php if (isset($_SESSION["username"])): ?>
                <button class="nav-user-chip" id="nav-user-chip" type="button" title="Edit profile" aria-label="Edit username or password">
                    <?= htmlspecialchars($_SESSION["username"]) ?>
                </button>
            <?php endif; ?>

            <a class="btn btn-ghost nav-logout-desktop" href="<?= BASE_PATH ?>/logout.php">Logout</a>

            <button class="nav-burger" id="nav-burger" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="nav-links">
                <span></span>
                <span></span>
                <span></span>
            </button>
        <?php else: ?>
            <a class="btn btn-ghost" href="<?= BASE_PATH ?>/login.php">Login</a>
            <a class="btn btn-primary" href="<?= BASE_PATH ?>/register.php">Register</a>
        <?php endif; ?>
    </div>
</nav>

<?php if ($loggedIn): ?>
<!-- Profile-picture upload modal (opened from the nav avatar button) -->
<div id="avatar-modal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog glass-card modal-solid avatar-dialog">
        <div class="avatar-modal-head">
            <h3>Profile picture</h3>
            <button type="button" class="icon-btn" id="avatar-close" aria-label="Close">✕</button>
        </div>
        <div class="avatar-preview" id="avatar-preview">
            <?php if ($myAvatar): ?>
                <img src="<?= htmlspecialchars($myAvatar) ?>" alt="">
            <?php else: ?>
                <span class="nav-avatar-fallback big"><?= htmlspecialchars($myInitial) ?></span>
            <?php endif; ?>
        </div>
        <input type="file" id="avatar-file" accept="image/png,image/jpeg,image/webp" hidden>
        <p class="avatar-hint muted">PNG, JPG or WebP — it's cropped to a square and resized.</p>
        <div class="modal-actions">
            <button type="button" class="btn btn-danger" id="avatar-remove">Remove</button>
            <button type="button" class="btn" id="avatar-pick">Choose image</button>
            <button type="button" class="btn-primary" id="avatar-save" disabled>Save</button>
        </div>
        <p class="avatar-feedback" id="avatar-feedback"></p>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById("avatar-modal");
    if (!modal) return;
    const BASE     = "<?= BASE_PATH ?>";
    const INIT     = <?= json_encode($myInitial) ?>;
    const navBtn   = document.getElementById("nav-avatar");
    const preview  = document.getElementById("avatar-preview");
    const fileEl   = document.getElementById("avatar-file");
    const saveBtn  = document.getElementById("avatar-save");
    const feedback = document.getElementById("avatar-feedback");
    let pending = null; // resized data URL awaiting save

    const open  = () => { feedback.textContent = ""; pending = null; saveBtn.disabled = true; modal.style.display = "flex"; };
    const close = () => { modal.style.display = "none"; };
    navBtn.addEventListener("click", open);
    document.getElementById("avatar-close").addEventListener("click", close);
    modal.addEventListener("click", (e) => { if (e.target === modal) close(); });
    document.getElementById("avatar-pick").addEventListener("click", () => fileEl.click());

    function fallbackHtml(big) {
        const span = document.createElement("span");
        span.className = "nav-avatar-fallback" + (big ? " big" : "");
        span.textContent = INIT;
        return span;
    }
    function imgHtml(src) {
        const img = document.createElement("img");
        img.src = src; img.alt = "";
        return img;
    }
    function setNav(src) {
        navBtn.replaceChildren(src ? imgHtml(src) : fallbackHtml(false));
    }

    fileEl.addEventListener("change", () => {
        const file = fileEl.files && fileEl.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => {
            const img = new Image();
            img.onload = () => {
                // Center-crop to a square, resize to 256px, export as JPEG.
                const size = 256;
                const c = document.createElement("canvas");
                c.width = c.height = size;
                const ctx  = c.getContext("2d");
                const side = Math.min(img.width, img.height);
                const sx = (img.width - side) / 2, sy = (img.height - side) / 2;
                ctx.drawImage(img, sx, sy, side, side, 0, 0, size, size);
                pending = c.toDataURL("image/jpeg", 0.85);
                preview.replaceChildren(imgHtml(pending));
                saveBtn.disabled = false;
            };
            img.src = reader.result;
        };
        reader.readAsDataURL(file);
        fileEl.value = ""; // allow re-picking the same file
    });

    saveBtn.addEventListener("click", () => {
        if (!pending) return;
        saveBtn.disabled = true;
        feedback.textContent = "Saving…";
        const body = new FormData();
        body.append("avatar", pending);
        fetch(BASE + "/api/upload-avatar.php", { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Upload failed."); }))
            .then(res => { setNav(res.avatar); feedback.textContent = "Saved."; setTimeout(close, 600); })
            .catch(err => { feedback.textContent = err.message; saveBtn.disabled = false; });
    });

    document.getElementById("avatar-remove").addEventListener("click", () => {
        feedback.textContent = "Removing…";
        const body = new FormData();
        body.append("remove", "1");
        fetch(BASE + "/api/upload-avatar.php", { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Failed."); }))
            .then(() => {
                setNav(null);
                preview.replaceChildren(fallbackHtml(true));
                pending = null; saveBtn.disabled = true;
                feedback.textContent = "Removed.";
            })
            .catch(err => { feedback.textContent = err.message; });
    });
}());
</script>

<!-- Profile-credentials modal (opened from the username chip in the nav) -->
<div id="profile-modal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog glass-card modal-solid profile-dialog">
        <div class="avatar-modal-head">
            <h3>Edit profile</h3>
            <button type="button" class="icon-btn" id="profile-close" aria-label="Close">&#x2715;</button>
        </div>

        <label for="profile-username">Username</label>
        <input type="text" id="profile-username" maxlength="50" autocomplete="username">

        <label for="profile-current-pw">Current password <span class="muted">(required)</span></label>
        <input type="password" id="profile-current-pw" autocomplete="current-password">

        <label for="profile-new-pw">New password <span class="muted">(optional)</span></label>
        <input type="password" id="profile-new-pw" placeholder="Leave blank to keep current" autocomplete="new-password">

        <div class="modal-actions">
            <button type="button" class="btn" id="profile-cancel">Cancel</button>
            <button type="button" class="btn-primary" id="profile-save">Save</button>
        </div>
        <p class="profile-feedback" id="profile-feedback"></p>
    </div>
</div>

<script>
(function () {
    const modal    = document.getElementById("profile-modal");
    if (!modal) return;
    const BASE     = "<?= BASE_PATH ?>";
    let currentUser = <?= json_encode($_SESSION["username"] ?? "") ?>;

    const chipBtn   = document.getElementById("nav-user-chip");
    const userEl    = document.getElementById("profile-username");
    const curPwEl   = document.getElementById("profile-current-pw");
    const newPwEl   = document.getElementById("profile-new-pw");
    const saveBtn   = document.getElementById("profile-save");
    const feedback  = document.getElementById("profile-feedback");

    function open() {
        userEl.value   = currentUser;
        curPwEl.value  = "";
        newPwEl.value  = "";
        feedback.textContent = "";
        feedback.className   = "profile-feedback";
        saveBtn.disabled     = false;
        modal.style.display  = "flex";
        setTimeout(() => { userEl.focus(); userEl.select(); }, 50);
    }
    function close() { modal.style.display = "none"; }

    if (chipBtn) chipBtn.addEventListener("click", open);
    document.getElementById("profile-close").addEventListener("click", close);
    document.getElementById("profile-cancel").addEventListener("click", close);
    modal.addEventListener("click", (e) => { if (e.target === modal) close(); });
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && modal.style.display !== "none") close();
    });

    saveBtn.addEventListener("click", () => {
        const newUsername = userEl.value.trim();
        const curPw       = curPwEl.value;
        const newPw       = newPwEl.value;

        if (!curPw) {
            feedback.textContent = "Enter your current password to save changes.";
            feedback.className   = "profile-feedback error";
            return;
        }

        const usernameChanged = newUsername !== "" && newUsername !== currentUser;
        const passwordChanged = newPw !== "";
        if (!usernameChanged && !passwordChanged) {
            feedback.textContent = "Nothing to update.";
            feedback.className   = "profile-feedback error";
            return;
        }

        feedback.textContent = "Saving…";
        feedback.className   = "profile-feedback";
        saveBtn.disabled     = true;

        const body = new FormData();
        if (usernameChanged) body.append("username", newUsername);
        body.append("current_password", curPw);
        if (passwordChanged) body.append("new_password", newPw);

        fetch(BASE + "/api/patch-profile.php", { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Request failed."); }))
            .then(res => {
                currentUser = res.username;
                if (chipBtn) chipBtn.textContent = res.username;
                // Update avatar fallback initial if it exists and no avatar is set
                const navAvatar = document.getElementById("nav-avatar");
                if (navAvatar) {
                    const fallback = navAvatar.querySelector(".nav-avatar-fallback");
                    if (fallback) fallback.textContent = (res.username[0] || "?").toUpperCase();
                }
                feedback.textContent = "Saved.";
                feedback.className   = "profile-feedback";
                setTimeout(close, 900);
            })
            .catch(err => {
                feedback.textContent = err.message;
                feedback.className   = "profile-feedback error";
                saveBtn.disabled     = false;
            });
    });
}());
</script>
<?php endif; ?>

<main class="container">
