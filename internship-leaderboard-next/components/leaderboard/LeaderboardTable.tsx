"use client";

import { Fragment, useState } from "react";

import { Badge } from "@/components/ui/badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

export type LeaderboardApplicationRow = {
  applicationId: number;
  companyName: string;
  jobTitle: string | null;
  jobLink: string | null;
  location: string | null;
  status: string;
  peakStatus: string | null;
  tag: string | null;
  notes: string | null;
  createdLabel: string;
  lastUpdateLabel: string;
  lastUpdateColor: "neutral" | "green" | "yellow" | "orange" | "red";
};

export type LeaderboardRow = {
  userId: number;
  username: string;
  totalApplications: number;
  pending: number;
  rejected: number;
  ghosted: number;
  interviews: number;
  offers: number;
  score: number;
  applications: LeaderboardApplicationRow[];
};

const DATE_BADGE_COLOR: Record<LeaderboardApplicationRow["lastUpdateColor"], string> = {
  neutral: "text-muted-foreground",
  green: "text-green-500",
  yellow: "text-yellow-500",
  orange: "text-orange-500",
  red: "text-red-500",
};

const RANK_MEDAL = ["🥇", "🥈", "🥉"];

export function LeaderboardTable({ users }: { users: LeaderboardRow[] }) {
  const [expanded, setExpanded] = useState<number | null>(null);

  if (users.length === 0) {
    return <p className="text-muted-foreground">No users yet.</p>;
  }

  return (
    <div data-no-tilt className="glow-card overflow-x-auto rounded-md border bg-card">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Rank</TableHead>
            <TableHead>User</TableHead>
            <TableHead>Sent</TableHead>
            <TableHead>Pending (2p)</TableHead>
            <TableHead>Rejected (1p)</TableHead>
            <TableHead>Ghosted (1p)</TableHead>
            <TableHead>Interviews (5-7-10-15p)</TableHead>
            <TableHead>Offers (10-14-20-30p)</TableHead>
            <TableHead>Score</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {users.map((user, index) => {
            const rank = index + 1;
            const isExpanded = expanded === user.userId;

            return (
              <Fragment key={user.userId}>
                <TableRow
                  className="cursor-pointer"
                  onClick={() => setExpanded(isExpanded ? null : user.userId)}
                >
                  <TableCell>{rank <= 3 ? RANK_MEDAL[rank - 1] : rank}</TableCell>
                  <TableCell className="font-medium">{user.username}</TableCell>
                  <TableCell>{user.totalApplications}</TableCell>
                  <TableCell>{user.pending}</TableCell>
                  <TableCell>{user.rejected}</TableCell>
                  <TableCell>{user.ghosted}</TableCell>
                  <TableCell>{user.interviews}</TableCell>
                  <TableCell>{user.offers}</TableCell>
                  <TableCell className="font-bold">{user.score}</TableCell>
                </TableRow>

                {isExpanded && (
                  <TableRow>
                    <TableCell colSpan={9} className="bg-muted/30 p-4">
                      {user.applications.length === 0 ? (
                        <p className="text-muted-foreground">No applications yet.</p>
                      ) : (
                        <Table>
                          <TableHeader>
                            <TableRow>
                              <TableHead>Tag</TableHead>
                              <TableHead>Company</TableHead>
                              <TableHead>Job</TableHead>
                              <TableHead>Status</TableHead>
                              <TableHead>Location</TableHead>
                              <TableHead>Link</TableHead>
                              <TableHead>Notes</TableHead>
                              <TableHead>Created</TableHead>
                              <TableHead>Last status update</TableHead>
                            </TableRow>
                          </TableHeader>
                          <TableBody>
                            {user.applications.map((app) => (
                              <TableRow key={app.applicationId}>
                                <TableCell>
                                  {app.tag && <Badge variant="outline">{app.tag}</Badge>}
                                </TableCell>
                                <TableCell>{app.companyName}</TableCell>
                                <TableCell>{app.jobTitle}</TableCell>
                                <TableCell>
                                  {app.peakStatus &&
                                  app.peakStatus !== app.status &&
                                  (app.peakStatus === "INTERVIEW" || app.peakStatus === "OFFER") ? (
                                    <div className="flex flex-col items-center gap-1">
                                      <Badge>{app.peakStatus}</Badge>
                                      <span className="text-xs text-muted-foreground">↓</span>
                                      <Badge variant="secondary">{app.status}</Badge>
                                    </div>
                                  ) : (
                                    <Badge variant="secondary">{app.status}</Badge>
                                  )}
                                </TableCell>
                                <TableCell>{app.location}</TableCell>
                                <TableCell>
                                  {app.jobLink && (
                                    <a
                                      href={app.jobLink}
                                      target="_blank"
                                      rel="noreferrer"
                                      onClick={(e) => e.stopPropagation()}
                                      className="underline underline-offset-4"
                                    >
                                      Open
                                    </a>
                                  )}
                                </TableCell>
                                <TableCell>{app.notes}</TableCell>
                                <TableCell>{app.createdLabel}</TableCell>
                                <TableCell className={DATE_BADGE_COLOR[app.lastUpdateColor]}>
                                  {app.lastUpdateLabel}
                                </TableCell>
                              </TableRow>
                            ))}
                          </TableBody>
                        </Table>
                      )}
                    </TableCell>
                  </TableRow>
                )}
              </Fragment>
            );
          })}
        </TableBody>
      </Table>
    </div>
  );
}
