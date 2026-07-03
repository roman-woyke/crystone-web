import { Badge } from "@/components/ui/badge";
import { ApplicationStatus } from "@/app/generated/prisma/enums";

const STATUS_VARIANT: Record<ApplicationStatus, "default" | "secondary" | "destructive" | "outline"> = {
  PENDING: "secondary",
  INTERVIEW: "default",
  OFFER: "default",
  REJECTED: "destructive",
  GHOSTED: "outline",
};

export function StatusBadge({ status }: { status: ApplicationStatus }) {
  return <Badge variant={STATUS_VARIANT[status]}>{status}</Badge>;
}
