import React from "react";

export const PALETTE = ["#38bdf8", "#a78bfa", "#4ade80", "#fb923c", "#f472b6", "#22d3ee", "#f87171", "#84cc16"];

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

export function fmt(value: number | null | undefined, digits = 1): string {
  const num = Number(value);
  if (!Number.isFinite(num)) return "–";
  return num.toFixed(digits);
}

export function fmtTime(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  // WIB (Asia/Jakarta) = UTC+7
  const wib = new Date(d.getTime() + 7 * 60 * 60 * 1000);
  return wib.toLocaleString("id-ID", {
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "Asia/Jakarta",
  });
}

export function fmtAxis(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit", timeZone: "Asia/Jakarta" });
}

export function fmtDate(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString("id-ID", { year: "numeric", month: "short", day: "numeric", timeZone: "Asia/Jakarta" });
}

export function fmtDateTime(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString("id-ID", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "Asia/Jakarta",
  });
}

export function getStatusColor(status: string): string {
  switch (status) {
    case "active": return "#4ade80";
    case "maintenance": return "#facc15";
    case "provisioned": return "#38bdf8";
    case "decommissioned": return "#f87171";
    default: return "#94a3b8";
  }
}

export function getStatusBadgeClass(status: string): string {
  switch (status) {
    case "active": return "good";
    case "maintenance": return "conflict";
    case "provisioned": return "conflict";
    case "decommissioned": return "bad";
    default: return "conflict";
  }
}

export function getQualityColor(flag: string): string {
  switch (flag) {
    case "GOOD": return "#4ade80";
    case "OUT_OF_RANGE": return "#facc15";
    case "SENSOR_ERROR": return "#f87171";
    case "CLOCK_DRIFT": return "#fb923c";
    case "LATE": return "#a78bfa";
    case "RAIN_INITIAL": return "#38bdf8";
    case "RAIN_RESET": return "#fb923c";
    default: return "#94a3b8";
  }
}

/**
 * Fill gaps in time series data with null values.
 * Ensures continuous time buckets for proper gap display in charts (connectNulls=false).
 * 
 * @param data - Array of data points with time key
 * @param timeKey - Key for timestamp (ISO string)
 * @param valueKeys - Keys that should have null for missing buckets
 * @param intervalMinutes - Expected interval in minutes (default 60 for hourly)
 * @returns Complete time series with null-filled gaps
 */
export function fillTimeGaps<T extends Record<string, unknown>>(
  data: T[],
  timeKey: keyof T,
  valueKeys: string[],
  intervalMinutes = 60
): T[] {
  if (data.length === 0) return [];

  // Parse timestamps and sort
  const parsed = data
    .map((d) => ({
      ...d,
      _ts: new Date(d[timeKey] as string).getTime(),
    }))
    .filter((d) => !Number.isNaN(d._ts))
    .sort((a, b) => a._ts - b._ts);

  if (parsed.length === 0) return [];

  const intervalMs = intervalMinutes * 60 * 1000;
  const startTs = parsed[0]._ts;
  const endTs = parsed[parsed.length - 1]._ts;

  // Create a map for quick lookup
  const dataMap = new Map<number, T>();
  for (const d of parsed) {
    dataMap.set(d._ts, d);
  }

  // Generate complete series
  const result: T[] = [];
  let currentTs = startTs;
  while (currentTs <= endTs) {
    const existing = dataMap.get(currentTs);
    if (existing) {
      result.push(existing);
    } else {
      // Create null-filled entry
      const nullEntry = {
        [timeKey]: new Date(currentTs).toISOString(),
        _ts: currentTs,
      } as Record<string, unknown>;
      for (const key of valueKeys) {
        nullEntry[key] = null;
      }
      result.push(nullEntry as T);
    }
    currentTs += intervalMs;
  }

  return result;
}