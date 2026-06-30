<style>
.details-cell {
    padding: 0 18px 18px;
    background: rgba(255, 255, 255, 0.02);
}

.details-cell table {
    margin-top: 14px;
    background: rgba(255, 255, 255, 0.02);
    -webkit-backdrop-filter: none;
    backdrop-filter: none;
}

.details-cell .status-stack {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}

.details-cell .status-stack .peak-arrow {
    opacity: 0.5;
    font-size: 0.9em;
}

@media (max-width: 800px) {
    table.responsive-table td.details-cell {
        display: block;
        text-align: left;
        overflow-x: auto;
    }

    table.responsive-table td.details-cell::before {
        display: none;
    }

    /* The nested per-user table keeps its grid layout and scrolls instead */
    .details-cell table,
    .details-cell tbody {
        display: table;
        width: 100%;
    }

    .details-cell tbody {
        display: table-row-group;
    }

    .details-cell tr {
        display: table-row;
        margin: 0;
        border: none;
        background: transparent;
    }

    .details-cell td,
    .details-cell th {
        display: table-cell;
        text-align: center;
        border-bottom: 1px solid var(--glass-border);
    }

    .details-cell td::before {
        display: none;
    }
}
</style>

    <div id="podium" class="podium" style="display:none;"></div>

    <div class="table-wrap">
    <table class="responsive-table">
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
    </div>

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

                buildPodium(users);

                tbody.innerHTML = "";

                users.forEach((user, index) => {
                    const row = document.createElement("tr");
                    row.style.cursor = "pointer";

                    const rank = index + 1;
                    const rankHtml = rank <= 3
                        ? `<span class="rank-medal rank-medal-${rank}">${rank}</span>`
                        : rank;

                    row.innerHTML = `
                        <td data-label="Rank">${rankHtml}</td>
                        <td data-label="User"><strong>${escapeHtml(user.username)}</strong></td>
                        <td data-label="Sent">${user.total_applications}</td>
                        <td data-label="Pending">${user.pending}</td>
                        <td data-label="Rejected">${user.rejected}</td>
                        <td data-label="Ghosted">${user.ghosted}</td>
                        <td data-label="Interviews">${user.interviews}</td>
                        <td data-label="Offers">${user.offers}</td>
                        <td data-label="Score"><strong class="score-cell" data-score="${user.score}">${user.score}</strong></td>
                    `;

                    const detailsRow = document.createElement("tr");
                    detailsRow.style.display = "none";

                    const detailsCell = document.createElement("td");
                    detailsCell.colSpan = 9;
                    detailsCell.className = "details-cell";
                    detailsCell.innerHTML = buildApplicationsHtml(user.applications);

                    detailsRow.appendChild(detailsCell);

                    row.addEventListener("click", () => {
                        // Empty string falls back to the stylesheet display value,
                        // which differs between desktop (table-row) and mobile cards.
                        detailsRow.style.display =
                            detailsRow.style.display === "none" ? "" : "none";
                    });

                    tbody.appendChild(row);
                    tbody.appendChild(detailsRow);
                });

                if (typeof window.countUp === "function") {
                    document.querySelectorAll(".score-cell").forEach(el => {
                        window.countUp(el, el.dataset.score, 900);
                    });
                }
            })
            .catch(error => {
                document.getElementById("leaderboard-body").innerHTML = `
                    <tr>
                        <td colspan="9">Could not load leaderboard.</td>
                    </tr>
                `;
            });

        function buildPodium(users) {
            const podium = document.getElementById("podium");
            const top = users.slice(0, 3);
            if (top.length === 0) return;

            podium.innerHTML = top.map((user, i) => {
                const rank = i + 1;
                return `
                    <div class="podium-card glass-card rank-${rank}">
                        <span class="podium-medal">${rank}</span>
                        <div class="podium-name">${escapeHtml(user.username)}</div>
                        <div class="podium-score gradient-text podium-score-value" data-score="${user.score}">${user.score}</div>
                        <div class="podium-sub">${user.offers} offers · ${user.interviews} interviews</div>
                    </div>
                `;
            }).join("");

            podium.style.display = "flex";

            if (typeof window.countUp === "function") {
                podium.querySelectorAll(".podium-score-value").forEach(el => {
                    window.countUp(el, el.dataset.score, 1100);
                });
            }
        }

        function buildApplicationsHtml(applications) {
            if (applications.length === 0) {
                return "<p>No applications yet.</p>";
            }

            let html = `
                <table>
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
                                    ? `<div class="status-stack">
                                            ${statusBadge(app.peak_status)}
                                            <span class="peak-arrow">↓</span>
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
                        <td>${escapeHtml(relativeDays(app.created_at))}</td>
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

        // Returns days elapsed since a DB timestamp string, or null. DB times are
        // local (Europe/Berlin) and so is the browser, so parse them as local.
        function daysSince(timestamp) {
            if (!timestamp) return null;
            const iso = String(timestamp).includes("T") ? timestamp : String(timestamp).replace(" ", "T");
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
            // DB timestamps are local (Europe/Berlin), as is the browser — parse as local.
            const iso = String(timestamp).includes("T") ? timestamp : String(timestamp).replace(" ", "T");
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
