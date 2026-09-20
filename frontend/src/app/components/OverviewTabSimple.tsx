"use client";

import { useState } from "react";
import { DashboardData, DeviceInfo } from "@/app/types";
import { fmt, fmtTime, fmtAxis, PALETTE, deviceColor, qualityBadge } from "@/app/utils";

interface OverviewTabSimpleProps {
  data: DashboardData | null;
  error: string | null;
  updatedAt: Date | null;
  onRefresh: () => void;
}

export function OverviewTabSimple({ data, error, updatedAt, onRefresh }: OverviewTabSimpleProps) {
  const [refreshing, setRefreshing] = useState(false);

  const handleRefresh = async () => {
    setRefreshing(true);
    await onRefresh();
    setRefreshing(false);
  };

  const deviceNames = data
    ? Array.from(new Set(data.series.map((s) => s.device_name)))
    : [];

  const chartData = data
    ? Array.from(
        data.series.reduce((map, point) => {
          const key = point.period_start;
          const entry = map.get(key) ?? { period_start: key };
          entry[point.device_name] = point.temperature_avg;
          map.set(key, entry);
          return map;
        }, new Map<string, Record<string, number | string | null>>()).values()
      ).sort((a, b) => String(a.period_start).localeCompare(String(b.period_start)))
    : [];

  return (
    <div>
      <div className="flex justify-between items-center mb-4">
        <button
          onClick={handleRefresh}
          disabled={refreshing}
          className="btn-secondary"
        >
          {refreshing ? "Refreshing..." : "Refresh"}
        </button>
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
        </div>
      </div>

      {!data && !error && <div className="card"><div className="empty">loading…</div></div>}

      {data && (
        <>
          <div className="grid">
            {data.devices.length === 0 && (
              <div className="card">
                <div className="empty">
                  No devices registered. Use Devices tab to add one.
                </div>
              </div>
            )}
            {data.devices.map((device) => {
              const l = device.latest;
              const loc = device.location;
              return (
                <div className="card" key={device.id} style={{ borderLeft: `4px solid ${getStatusColor(device.status)}` }}>
                  <div className="flex justify-between items-start">
                    <h3>{device.name}</h3>
                    <span className={`badge ${getStatusBadgeClass(device.status)}`}>
                      {device.status}
                    </span>
                  </div>
                  <div className="sub">
                    {loc?.name} · {loc?.country || "—"} · {device.device_code}
                  </div>
                  <div className="sub">
                    Rain today: {fmt(device.rain_today_mm, 2)} mm · Firmware: {device.firmware_version}
                  </div>

                  {l ? (
                    <>
                      <div className="reading">
                        <span className="value">{fmt(l.temperature_c)}</span>
                        <span className="unit">°C</span>
                        <span style={{ marginLeft: "auto" }}>
                          {qualityBadge(l.quality_score ?? null, [])}
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
                            {fmt(l.windspeed_ms)} m/s · {fmt(l.wind_direction_deg)}°
                          </b>
                        </div>
                        <div className="metric">
                          Rain Δ
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
            <h3 className="panel-title">Hourly temperature (°C) — last 24h</h3>
            {chartData.length === 0 ? (
              <div className="empty">no data yet</div>
            ) : (
              <div style={{ height: 280 }}>
                <svg viewBox="0 0 800 280" preserveAspectRatio="none" style={{ width: "100%", height: "100%" }}>
                  {deviceNames.map((name, i) => {
                    const color = PALETTE[i % PALETTE.length];
                    const points = chartData
                      .map((d, idx) => {
                        const val = d[name];
                        if (val === null || val === undefined) return null;
                        const x = 40 + (idx / Math.max(1, chartData.length - 1)) * 720;
                        const y = 240 - ((Number(val) + 40) / 80) * 220;
                        return `${x},${y}`;
                      })
                      .filter(Boolean)
                      .join(" ");
                    return points ? (
                      <polyline
                        key={name}
                        points={points}
                        fill="none"
                        stroke={color}
                        strokeWidth="2"
                      />
                    ) : null;
                  })}
                </svg>
              </div>
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
                        no readings yet
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
                      <td>{qualityBadge(r.quality_score ?? null, r.quality_flags ?? [])}</td>
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

function getStatusColor(status: string): string {
  switch (status) {
    case "active":
      return "#4ade80";
    case "maintenance":
      return "#facc15";
    case "provisioned":
      return "#38bdf8";
    case "decommissioned":
      return "#f87171";
    default:
      return "#94a3b8";
  }
}

function getStatusBadgeClass(status: string): string {
  switch (status) {
    case "active":
      return "good";
    case "maintenance":
      return "conflict";
    case "provisioned":
      return "conflict";
    case "decommissioned":
      return "bad";
    default:
      return "conflict";
  }
}