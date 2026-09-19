"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import type { ReactNode } from "react";
import {
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

interface LatestReading {
  taken_at: string | null;
  temperature_c: number | null;
  humidity_pct: number | null;
  pressure_hpa: number | null;
  windspeed_ms: number | null;
  wind_direction_deg: number | null;
  rain_counter: number | null;
  rain_delta_mm: number | null;
  quality_score: number | null;
}

interface DeviceInfo {
  id: string;
  name: string;
  location: Record<string, string>;
  rain_today_mm: number;
  latest: LatestReading | null;
}

interface SeriesPoint {
  period_start: string;
  device_name: string;
  temperature_avg: number | null;
  humidity_avg: number | null;
  pressure_avg: number | null;
  windspeed_avg: number | null;
  wind_direction_avg: number | null;
  rain_total_mm: number;
}

interface RecentRow {
  taken_at: string;
  device_name: string;
  temperature_c: number | null;
  humidity_pct: number | null;
  pressure_hpa: number | null;
  windspeed_ms: number | null;
  wind_direction_deg: number | null;
  rain_counter: number | null;
  rain_delta_mm: number | null;
  quality_score: number | null;
  quality_flags: string[];
}

interface DashboardData {
  devices: DeviceInfo[];
  series: SeriesPoint[];
  recent: RecentRow[];
}

const PALETTE = ["#38bdf8", "#a78bfa", "#4ade80", "#fb923c", "#f472b6"];

function deviceColor(name: string, names: string[]): string {
  const idx = names.indexOf(name);
  return PALETTE[idx % PALETTE.length] ?? PALETTE[0];
}

function qualityBadge(score: number | null, flags: string[]): ReactNode {
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

function fmt(value: number | null, digits = 1): string {
  const num = Number(value);
  if (!Number.isFinite(num)) return "–";
  return num.toFixed(digits);
}

function fmtTime(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString([], {
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function fmtAxis(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

export default function DashboardPage() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [updatedAt, setUpdatedAt] = useState<Date | null>(null);

  const refresh = useCallback(async () => {
    try {
      const res = await fetch("/api/dashboard", { cache: "no-store" });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const json: DashboardData = await res.json();
      setData(json);
      setError(null);
      setUpdatedAt(new Date());
    } catch (err) {
      setError(err instanceof Error ? err.message : "failed to load data");
    }
  }, []);

  useEffect(() => {
    refresh();
    const interval = window.setInterval(refresh, 10_000);
    return () => window.clearInterval(interval);
  }, [refresh]);

  const deviceNames = useMemo(
    () =>
      data && Array.isArray(data.series)
        ? Array.from(new Set(data.series.map((s) => s.device_name)))
        : [],
    [data],
  );

  const chartData = useMemo(() => {
    const map = new Map<string, Record<string, number | string | null>>();
    for (const point of data?.series ?? []) {
      const key = point.period_start;
      const entry = map.get(key) ?? { period_start: key };
      entry[point.device_name] = point.temperature_avg;
      map.set(key, entry);
    }
    return Array.from(map.values()).sort((a, b) =>
      String(a.period_start).localeCompare(String(b.period_start)),
    );
  }, [data]);

  const apiBase =
    process.env.NEXT_PUBLIC_API_URL || "http://localhost:8080";

  return (
    <div className="container">
      <header className="header">
        <h1>Weather Station Monitor</h1>
        <div className="status">
          {error ? (
            <>
              <span className="dot" />
              <span>unreachable: {error}</span>
            </>
          ) : (
            <>
              <span className="dot ok" />
              <span>
                updated {updatedAt ? fmtTime(updatedAt.toISOString()) : "…"}
              </span>
            </>
          )}
          <span>
            API <a href={apiBase} target="_blank" rel="noreferrer">{apiBase}</a>
          </span>
        </div>
      </header>

      {!data && !error && (
        <div className="card">
          <div className="empty">connecting to backend…</div>
        </div>
      )}

      {data && (
        <>
          <div className="grid">
            {data.devices.length === 0 && (
              <div className="card">
                <div className="empty">
                  No devices registered. Seed a device or ingest telemetry to
                  get started.
                </div>
              </div>
            )}
            {data.devices.map((device) => {
              const l = device.latest;
              return (
                <div className="card" key={device.id}>
                  <h3>{device.name}</h3>
                  <div className="sub">
                    {Object.entries(device.location)
                      .map(([k, v]) => `${v}`)
                      .join(" · ") || "—"}
                    {" · "}rain today {fmt(device.rain_today_mm, 2)} mm
                  </div>
                  {l ? (
                    <>
                      <div className="reading">
                        <span className="value">{fmt(l.temperature_c)}</span>
                        <span className="unit">°C</span>
                        <span style={{ marginLeft: "auto" }}>
                          {qualityBadge(l.quality_score, [])}
                        </span>
                      </div>
                      <div className="metric-row">
                        <div className="metric">
                          Humidity
                          <b>{fmt(l.humidity_pct)}%</b>
                        </div>
                        <div className="metric">
                          Pressure
                          <b>{fmt(l.pressure_hpa)} hPa</b>
                        </div>
                        <div className="metric">
                          Wind
                          <b>
                            {fmt(l.windspeed_ms)} m/s ·{" "}
                            {fmt(l.wind_direction_deg)}°
                          </b>
                        </div>
                        <div className="metric">
                          Rain last
                          <b>{fmt(l.rain_delta_mm, 2)} mm</b>
                        </div>
                      </div>
                      <div className="sub" style={{ marginTop: 10 }}>
                        last updated {l.taken_at ? fmtTime(l.taken_at) : "—"}
                      </div>
                    </>
                  ) : (
                    <div className="empty">no readings yet</div>
                  )}
                </div>
              );
            })}
          </div>

          <div className="chart">
            <h3 className="panel-title">
              Hourly temperature (°C) — last 24h
            </h3>
            {chartData.length === 0 ? (
              <div className="empty">no data yet</div>
            ) : (
              <ResponsiveContainer width="100%" height={280}>
                <LineChart data={chartData} margin={{ top: 8, right: 16, left: -14, bottom: 0 }}>
                  <CartesianGrid stroke="#334155" strokeDasharray="3 3" />
                  <XAxis
                    dataKey="period_start"
                    tickFormatter={fmtAxis}
                    stroke="#94a3b8"
                    fontSize={11}
                  />
                  <YAxis stroke="#94a3b8" fontSize={11} domain={["auto", "auto"]} />
                  <Tooltip
                    labelFormatter={fmtTime}
                    contentStyle={{
                      background: "#1e293b",
                      border: "1px solid #334155",
                      borderRadius: 8,
                      color: "#e2e8f0",
                    }}
                  />
                  {deviceNames.map((name, i) => (
                    <Line
                      key={name}
                      type="monotone"
                      dataKey={name}
                      stroke={PALETTE[i % PALETTE.length]}
                      strokeWidth={2}
                      dot={false}
                      connectNulls
                    />
                  ))}
                </LineChart>
              </ResponsiveContainer>
            )}
          </div>

          <div className="card">
            <h3 className="panel-title">Recent readings</h3>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Time</th>
                    <th>Device</th>
                    <th>Temp °C</th>
                    <th>Humidity %</th>
                    <th>Pressure hPa</th>
                    <th>Wind m/s</th>
                    <th>Dir °</th>
                    <th>Rain Δ mm</th>
                    <th>Quality</th>
                  </tr>
                </thead>
                <tbody>
                  {data.recent.length === 0 && (
                    <tr>
                      <td colSpan={9} className="empty">
                        no readings yet — POST some telemetry to see it here
                      </td>
                    </tr>
                  )}
                  {data.recent.map((r, i) => (
                    <tr key={i}>
                      <td>{fmtTime(r.taken_at)}</td>
                      <td>{r.device_name}</td>
                      <td>{fmt(r.temperature_c)}</td>
                      <td>{fmt(r.humidity_pct)}</td>
                      <td>{fmt(r.pressure_hpa)}</td>
                      <td>{fmt(r.windspeed_ms)}</td>
                      <td>{fmt(r.wind_direction_deg)}</td>
                      <td>{fmt(r.rain_delta_mm, 2)}</td>
                      <td>{qualityBadge(r.quality_score, r.quality_flags)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  );
}