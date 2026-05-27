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
                                    ? `<div style="display:flex; flex-direction:column; align-items:center; gap:4px;">
                                            ${statusBadge(app.peak_status)}
                                            <span style="opacity:0.5; font-size:0.9em;">↓</span>
                                            ${statusBadge(app.status)}
                                       </div>`
                                    : statusBadge(app.status)
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
                        <td>${neutralDateBadge(relativeDays(app.created_at))}</td>
                        <td>${lastStatusBadge(app.last_status_change ?? app.updated_at, app.peak_status, app.status)}</td>
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

        function statusBadge(status) {
            if (!status) return "";
            const slug = status.toLowerCase();
            return `<span class="status-badge status-badge-${slug}">${escapeHtml(status)}</span>`;
        }

        // Returns days elapsed since a UTC timestamp string, or null.
        function daysSince(timestamp) {
            if (!timestamp) return null;
            const iso = String(timestamp).includes("T") ? timestamp : String(timestamp).replace(" ", "T") + "Z";
            const then = new Date(iso);
            if (isNaN(then)) return null;
            return Math.floor((Date.now() - then.getTime()) / 86400000);
        }

        function neutralDateBadge(label) {
            if (!label || label === "—") return "—";
            return `<span class="date-badge date-badge-neutral">${escapeHtml(label)}</span>`;
        }

        function lastStatusBadge(timestamp, peakStatus, status) {
            const days = daysSince(timestamp);
            if (days === null) return "—";
            const label = days <= 0 ? "today" : `${days}d ago`;

            // Skip coloring when the app reached interview/offer or is already rejected.
            if (peakStatus === "INTERVIEW" || peakStatus === "OFFER" || status === "REJECTED") {
                return neutralDateBadge(label);
            }

            const color = days <= 7 ? "green"
                        : days <= 14 ? "yellow"
                        : days <= 30 ? "orange"
                        : "red";
            return `<span class="date-badge date-badge-${color}">${escapeHtml(label)}</span>`;
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
            return `${diffDays}d ago`;
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