import React from "react";

export const PALETTE = ["#38bdf8", "#a78bfa", "#4ade80", "#fb923c", "#f472b6"];

export function deviceColor(name: string, names: string[]): string {
  const idx = names.indexOf(name);
  return PALETTE[idx % PALETTE.length] ?? PALETTE[0];
}

export function qualityBadge(score: number | null, flags: string[]): React.ReactNode {
  if (score !== null && score >= 80 && flags.length === 0) {
    return <span className="badge good">excellent ({score})</span>;
  }
  if (flags.length > 0) {
    return (
      <>
        <span className="badge conflict">{score ?? "–"} </span>
        <span className="badge flags">{flags.join(", ")}</span>
      </>
    );
  }
  return <span className="badge conflict">{score ?? "–"}</span>;
}

export function fmt(value: number | null, digits = 1): string {
  const num = Number(value);
  if (!Number.isFinite(num)) return "–";
  return num.toFixed(digits);
}

export function fmtTime(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString([], {
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export function fmtAxis(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}