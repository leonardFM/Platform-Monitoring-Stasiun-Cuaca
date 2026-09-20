"use client";

import { useState, useEffect, useCallback } from "react";
import { api } from "@/app/lib/api";
import { DeviceInfo, DeviceHealth, StaleDevice } from "@/app/types";
import { fmtTime, fmt, getStatusColor } from "@/app/utils";
import { Pagination } from "@/app/components/Pagination";

interface HealthTabProps {
  devices: DeviceInfo[];
}

export function HealthTab({ devices }: HealthTabProps) {
  const [healthMap, setHealthMap] = useState<Record<string, DeviceHealth>>({});
  const [staleDevices, setStaleDevices] = useState<StaleDevice[]>([]);
  const [loading, setLoading] = useState(true);
  const [staleLoading, setStaleLoading] = useState(true);
  const [threshold, setThreshold] = useState(15);
  const [stalePagination, setStalePagination] = useState({ current_page: 1, last_page: 1, per_page: 20, total: 0 });

  const fetchAllHealth = useCallback(async () => {
    setLoading(true);
    try {
      const healthData: Record<string, DeviceHealth> = {};
      for (const device of devices) {
        try {
          const h = await api.health.get(device.id);
          healthData[device.id] = h;
        } catch {
          healthData[device.id] = { device_id: device.id, battery_voltage: null, rssi: null, firmware_version: null, last_heartbeat_at: null, uptime_seconds: null, is_stale: true, stale_minutes: null };
        }
      }
      setHealthMap(healthData);
    } finally {
      setLoading(false);
    }
  }, [devices]);

  const fetchStale = useCallback(async () => {
    setStaleLoading(true);
    try {
      const res = await api.health.stale(threshold);
      setStaleDevices(res.data);
      setStalePagination({ current_page: 1, last_page: 1, per_page: res.data.length, total: res.data.length });
    } finally {
      setStaleLoading(false);
    }
  }, [threshold]);

  useEffect(() => { fetchAllHealth(); }, [fetchAllHealth]);
  useEffect(() => { fetchStale(); }, [fetchStale]);

  if (loading) return <div className="empty">Loading health data…</div>;

  const getBatteryColor = (v: number | null) => {
    if (v === null) return "#94a3b8";
    if (v >= 3.7) return "#4ade80";
    if (v >= 3.3) return "#facc15";
    return "#f87171";
  };

  const getRssiColor = (v: number | null) => {
    if (v === null) return "#94a3b8";
    if (v >= -70) return "#4ade80";
    if (v >= -85) return "#facc15";
    return "#f87171";
  };

  return (
    <div>
      <div className="flex justify-between items-center mb-4">
        <h2>Device Health</h2>
        <div className="control-group">
          <label>Stale Threshold (min)</label>
          <input type="number" value={threshold} onChange={e => setThreshold(Number(e.target.value))} min={1} max={1440} style={{ width: "80px" }} />
        </div>
      </div>

      <div className="grid">
        {devices.map(device => {
          const health = healthMap[device.id];
          const isStale = health?.is_stale ?? false;
          const battery = health?.battery_voltage ?? null;
          const rssi = health?.rssi ?? null;
          const fw = health?.firmware_version ?? device.firmware_version;
          const lastHb = health?.last_heartbeat_at ?? device.last_seen_at;
          const uptime = health?.uptime_seconds;

          return (
            <div className="card" key={device.id} style={{ borderLeft: `4px solid ${isStale ? "#f87171" : getStatusColor(device.status)}` }}>
              <div className="flex justify-between items-start">
                <h3>{device.name}</h3>
                <span className={`badge ${isStale ? "bad" : getStatusColor(device.status) === "#4ade80" ? "good" : "conflict"}`} style={{ background: `${isStale ? "#f87171" : getStatusColor(device.status)}20`, color: isStale ? "#f87171" : getStatusColor(device.status) }}>
                  {isStale ? "STALE" : device.status}
                </span>
              </div>
              <div className="sub">{device.device_code} · {device.location?.name}</div>

              <div className="health-metrics">
                <div className="metric">
                  Battery
                  <b style={{ color: getBatteryColor(battery) }}>{battery !== null ? fmt(battery, 2) + " V" : "—"}</b>
                </div>
                <div className="metric">
                  RSSI
                  <b style={{ color: getRssiColor(rssi) }}>{rssi !== null ? rssi + " dBm" : "—"}</b>
                </div>
                <div className="metric">
                  Firmware
                  <b>{fw || "—"}</b>
                </div>
                <div className="metric">
                  Last Heartbeat
                  <b>{lastHb ? fmtTime(lastHb) : "—"}</b>
                </div>
                {uptime !== null && uptime !== undefined && (
                  <div className="metric">
                    Uptime
                    <b>{Math.floor(uptime / 3600)}h {Math.floor((uptime % 3600) / 60)}m</b>
                  </div>
                )}
              </div>
            </div>
          );
        })}
      </div>

      <div className="chart-section">
        <h3 className="panel-title">{`Stale Devices (> ${threshold} min)`}</h3>
        {staleLoading ? (
          <div className="empty">Loading…</div>
        ) : staleDevices.length === 0 ? (
          <div className="card good"><div className="empty">✓ All devices are online</div></div>
        ) : (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Device</th>
                  <th>Code</th>
                  <th>Status</th>
                  <th>Last Seen</th>
                  <th>Stale (min)</th>
                  <th>Battery (V)</th>
                  <th>RSSI (dBm)</th>
                </tr>
              </thead>
              <tbody>
                {staleDevices.map(d => (
                  <tr key={d.id} style={{ background: d.stale_minutes && d.stale_minutes > 60 ? "rgba(248, 113, 113, 0.1)" : "" }}>
                    <td>{d.name}</td>
                    <td><code>{d.device_code}</code></td>
                    <td><span className="badge bad">{d.status}</span></td>
                    <td>{d.last_seen_at ? fmtTime(d.last_seen_at) : "—"}</td>
                    <td>{d.stale_minutes !== null ? d.stale_minutes : "—"}</td>
                    <td style={{ color: getBatteryColor(d.battery_voltage) }}>{d.battery_voltage !== null ? fmt(d.battery_voltage, 2) : "—"}</td>
                    <td style={{ color: getRssiColor(d.rssi) }}>{d.rssi !== null ? d.rssi : "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}