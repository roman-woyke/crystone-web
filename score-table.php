<table border="1" cellpadding="10" width="100%">
        <thead>
            <tr>
                <th>Rank</th>
                <th>User</th>
                <th>Sent</th>
                <th>Pending (2p)</th>
                <th>Rejected (1p)</th>
                <th>Ghosted (1p)</th>
                <th>Interviews (5-7-10-15p)</th>
                <th>Offers (10-14-20-30p)</th>
                <th>Score</th>
            </tr>
        </thead>

        <tbody id="leaderboard-body">
            <tr>
                <td colspan="9">Loading leaderboard...</td>
            </tr>
        </tbody>
    </table>

    <script>
        fetch("<?= BASE_PATH ?>/api/get-leaderboard.php")
            .then(response => {
                if (!response.ok) {
                    throw new Error("Failed to load leaderboard.");
                }

                return response.json();
            })
            .then(users => {
                const tbody = document.getElementById("leaderboard-body");

                if (users.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="9">No users yet.</td>
                        </tr>
                    `;
                    return;
                }

                tbody.innerHTML = "";

                users.forEach((user, index) => {
                    const row = document.createElement("tr");
                    row.style.cursor = "pointer";

                    row.innerHTML = `
                        <td>${index + 1}</td>
                        <td>${escapeHtml(user.username)}</td>
                        <td>${user.total_applications}</td>
                        <td>${user.pending}</td>
                        <td>${user.rejected}</td>
                        <td>${user.ghosted}</td>
                        <td>${user.interviews}</td>
                        <td>${user.offers}</td>
                        <td>${user.score}</td>
                    `;

                    const detailsRow = document.createElement("tr");
                    detailsRow.style.display = "none";

                    const detailsCell = document.createElement("td");
                    detailsCell.colSpan = 9;
                    detailsCell.innerHTML = buildApplicationsHtml(user.applications);

                    detailsRow.appendChild(detailsCell);

                    row.addEventListener("click", () => {
                        detailsRow.style.display =
                            detailsRow.style.display === "none" ? "table-row" : "none";
                    });

                    tbody.appendChild(row);
                    tbody.appendChild(detailsRow);
                });
            })
            .catch(error => {
                document.getElementById("leaderboard-body").innerHTML = `
                    <tr>
                        <td colspan="9">Could not load leaderboard.</td>
                    </tr>
                `;
            });

        function buildApplicationsHtml(applications) {
            if (applications.length === 0) {
                return "<p>No applications yet.</p>";
            }

            let html = `
                <table border="1" cellpadding="8" width="100%">
                    <thead>
                        <tr>
                            <th>Tag</th>
                            <th>Company</th>
                            <th>Job</th>
                            <th>Status</th>
                            <th>Location</th>
                            <th>Link</th>
                            <th>Notes</th>
                            <th>Created</th>
                            <th>Last status update</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            applications.forEach(app => {
                html += `
                    <tr>
                        <td>${tagBadge(app.tag)}</td>
                        <td>${escapeHtml(app.company_name)}</td>
                        <td>${escapeHtml(app.job_title ?? "")}</td>
                        <td>${
                                (app.peak_status && app.peak_status !== app.status && (app.peak_status === 'INTERVIEW' || app.peak_status === 'OFFER'))
                                    ? `<div style="display:flex; flex-direction:column; align-items:center; gap:2px;">
                                            <span>${escapeHtml(app.peak_status)}</span>
                                            <span style="opacity:0.4; font-size:0.8em;">↓</span>
                                            <span>${escapeHtml(app.status)}</span>
                                       </div>`
                                    : escapeHtml(app.status)
                            }</td>
                        <td>${escapeHtml(app.location ?? "")}</td>
                        <td>
                            ${
                                app.job_link
                                    ? `<a href="${escapeHtml(app.job_link)}" target="_blank" onclick="event.stopPropagation()">Open</a>`
                                    : ""
                            }
                        </td>
                        <td>${escapeHtml(app.notes ?? "")}</td>
                        <td>${escapeHtml(relativeDays(app.created_at))}</td>
                        <td>${escapeHtml(relativeDays(app.last_status_change ?? app.updated_at))}</td>
                    </tr>
                `;
            });

            html += `
                    </tbody>
                </table>
            `;

            return html;
        }

        function tagBadge(tag) {
            if (!tag) return "";
            const slug = tag.toLowerCase().replace(/ /g, '-');
            return `<span class="tag-badge tag-badge-${slug}">${escapeHtml(tag)}</span>`;
        }

        function relativeDays(timestamp) {
            if (!timestamp) return "—";
            // Treat DB timestamps (no TZ suffix) as UTC so day-count math is consistent.
            const iso = String(timestamp).includes("T") ? timestamp : String(timestamp).replace(" ", "T") + "Z";
            const then = new Date(iso);
            if (isNaN(then)) return "—";
            const diffMs   = Date.now() - then.getTime();
            const diffDays = Math.floor(diffMs / 86400000);
            if (diffDays <= 0) return "today";
            if (diffDays === 1) return "1 day ago";
            return `${diffDays} days ago`;
        }

        function escapeHtml(value) {
            return String(value)
                .replaceAll("&", "&amp;")
                .replaceAll("<", "&lt;")
                .replaceAll(">", "&gt;")
                .replaceAll('"', "&quot;")
                .replaceAll("'", "&#039;");
        }
    </script>