"use client";

import React, { useState, useMemo } from "react";
import type { DashboardData, DeviceInfo, SeriesPoint, RecentRow, Location, ChartDataPoint } from "@/app/types";
import { fmt, fmtTime, PALETTE, qualityBadge, getStatusColor, getStatusBadgeClass, fillTimeGaps } from "@/app/utils";
import { LineChartWidget, DualAxisChart, BarChartWidget, AreaChartWidget } from "@/app/components/Charts";

interface OverviewTabProps {
  data: DashboardData | null;
  error: string | null;
  updatedAt: Date | null;
  onRefresh: () => void;
}

export function OverviewTab({ data, error, updatedAt, onRefresh }: OverviewTabProps) {
  const [refreshing, setRefreshing] = useState(false);
  const [chartType, setChartType] = useState<"temp" | "dual" | "rain" | "wind">("temp");

  const handleRefresh = async () => {
    setRefreshing(true);
    await onRefresh();
    setRefreshing(false);
  };

  const deviceNames = data
    ? Array.from(new Set(data.series.map((s) => s.device_name)))
    : [];

  // Transform series data for charts with gap filling
  const chartData = useMemo(() => {
    if (!data) return [] as ChartDataPoint[];

    // Build base data grouped by period_start
    const baseMap = new Map<string, ChartDataPoint>();
    for (const point of data.series) {
      const key = point.period_start;
      const entry = baseMap.get(key) ?? { period_start: key };
      entry[point.device_name] = point.temperature_avg;
      baseMap.set(key, entry);
    }

    const baseData = Array.from(baseMap.values()).sort((a, b) =>
      String(a.period_start).localeCompare(String(b.period_start))
    );

    // Fill gaps with null values (1 hour interval)
    return fillTimeGaps(baseData, "period_start", deviceNames, 60);
  }, [data]);

  const humidityChartData = useMemo(() => {
    if (!data) return [] as ChartDataPoint[];

    const baseMap = new Map<string, ChartDataPoint>();
    for (const point of data.series) {
      const key = point.period_start;
      const entry = baseMap.get(key) ?? { period_start: key };
      entry[point.device_name] = point.humidity_avg;
      baseMap.set(key, entry);
    }

    const baseData = Array.from(baseMap.values()).sort((a, b) =>
      String(a.period_start).localeCompare(String(b.period_start))
    );

    return fillTimeGaps(baseData, "period_start", deviceNames, 60);
  }, [data]);

  const rainChartData = useMemo(() => {
    if (!data) return [] as ChartDataPoint[];

    const baseMap = new Map<string, ChartDataPoint>();
    for (const point of data.series) {
      const key = point.period_start;
      const entry = baseMap.get(key) ?? { period_start: key };
      entry[point.device_name] = point.rain_total_mm;
      baseMap.set(key, entry);
    }

    const baseData = Array.from(baseMap.values()).sort((a, b) =>
      String(a.period_start).localeCompare(String(b.period_start))
    );

    return fillTimeGaps(baseData, "period_start", deviceNames, 60);
  }, [data]);

  const windChartData = useMemo(() => {
    if (!data) return [] as ChartDataPoint[];

    const baseMap = new Map<string, ChartDataPoint>();
    for (const point of data.series) {
      const key = point.period_start;
      const entry = baseMap.get(key) ?? { period_start: key };
      entry[point.device_name + "_speed"] = point.windspeed_avg;
      entry[point.device_name + "_dir"] = point.wind_direction_avg;
      baseMap.set(key, entry);
    }

    const baseData = Array.from(baseMap.values()).sort((a, b) =>
      String(a.period_start).localeCompare(String(b.period_start))
    );

    const windKeys = deviceNames.flatMap((name) => [name + "_speed", name + "_dir"]);
    return fillTimeGaps(baseData, "period_start", windKeys, 60);
  }, [data]);

  const tempLines = deviceNames.map(name => ({ key: name, name, unit: "°C" }));
  const humidityLines = deviceNames.map(name => ({ key: name, name, unit: "%" }));
  const rainLines = deviceNames.map(name => ({ key: name, name, unit: "mm" }));
  const windLines = deviceNames.flatMap(name => [
    { key: name + "_speed", name: `${name} speed`, unit: "m/s" },
    { key: name + "_dir", name: `${name} dir`, unit: "°" },
  ]);

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
                    <span className={`badge ${getStatusBadgeClass(device.status)}`} style={{ background: `${getStatusColor(device.status)}20`, color: getStatusColor(device.status) }}>
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

          <div className="controls">
            <label>Chart:</label>
            <select value={chartType} onChange={e => setChartType(e.target.value as any)} className="filter-select" style={{ width: "auto" }}>
              <option value="temp">Temperature</option>
              <option value="dual">Temp + Humidity</option>
              <option value="rain">Rainfall</option>
              <option value="wind">Wind Speed</option>
            </select>
          </div>

          <div className="chart">
            <h3 className="panel-title">
              {chartType === "temp" && "Hourly Temperature (°C) — last 24h"}
              {chartType === "dual" && "Temperature & Humidity (Dual Axis)"}
              {chartType === "rain" && "Hourly Rainfall (mm) — last 24h"}
              {chartType === "wind" && "Wind Speed (m/s) — last 24h"}
            </h3>
            {chartData.length === 0 ? (
              <div className="empty">no data yet</div>
            ) : (
              <>
                {chartType === "temp" && (
                  <LineChartWidget data={chartData as ChartDataPoint[]} lines={tempLines} height={320} />
                )}
                {chartType === "dual" && (
                  <DualAxisChart
                    data={chartData as ChartDataPoint[]}
                    leftLines={tempLines}
                    rightLines={humidityLines}
                    height={320}
                  />
                )}
                {chartType === "rain" && (
                  <BarChartWidget data={rainChartData as ChartDataPoint[]} bars={rainLines} height={320} />
                )}
                {chartType === "wind" && (
                  <LineChartWidget data={windChartData as ChartDataPoint[]} lines={windLines.filter(l => l.key.includes("_speed"))} height={320} />
                )}
              </>
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
                      <td colSpan={9} className="empty">no readings yet</td>
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