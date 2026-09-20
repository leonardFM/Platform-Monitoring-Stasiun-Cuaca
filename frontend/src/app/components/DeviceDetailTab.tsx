"use client";

import { useState, useEffect, useCallback } from "react";
import { api } from "@/app/lib/api";
import { DeviceInfo, ReadingPoint, DeviceSensor, ChartDataPoint } from "@/app/types";
import { fmtTime, fmt, fmtDate, getQualityColor } from "@/app/utils";
import { LineChartWidget, DualAxisChart, BarChartWidget, AreaChartWidget } from "@/app/components/Charts";
import { Pagination } from "@/app/components/Pagination";

interface DeviceDetailTabProps {
  device: DeviceInfo;
  onBack: () => void;
}

export function DeviceDetailTab({ device, onBack }: DeviceDetailTabProps) {
  const [readings, setReadings] = useState<ReadingPoint[]>([]);
  const [sensors, setSensors] = useState<DeviceSensor[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, per_page: 50, total: 0 });
  const [interval, setInterval] = useState<"raw" | "1m" | "1h" | "1d">("1h");
  const [fromDate, setFromDate] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() - 7);
    return d.toISOString().split("T")[0];
  });
  const [toDate, setToDate] = useState(() => new Date().toISOString().split("T")[0]);
  const [agg, setAgg] = useState<"avg" | "min" | "max" | "sum">("avg");
  const [sensorFilter, setSensorFilter] = useState("");
  const [chartType, setChartType] = useState<"line" | "dual" | "bar" | "area">("line");
  const [selectedSensors, setSelectedSensors] = useState<string[]>([]);

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [readingsRes, sensorsRes] = await Promise.all([
        api.readings.list({
          device_id: device.id,
          interval,
          from: fromDate + "T00:00:00Z",
          to: toDate + "T23:59:59Z",
          agg,
          limit: pagination.per_page,
          offset: (pagination.current_page - 1) * pagination.per_page,
        }),
        api.devices.sensors(device.id),
      ]);
      setReadings(readingsRes.data);
      setPagination({ ...pagination, total: readingsRes.meta.total, last_page: Math.ceil(readingsRes.meta.total / pagination.per_page) });
      setSensors(sensorsRes.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load data");
    } finally {
      setLoading(false);
    }
  }, [device.id, interval, fromDate, toDate, agg, pagination.current_page, pagination.per_page]);

  useEffect(() => { fetchData(); }, [fetchData]);

  const sensorCodes = Array.from(new Set(readings.map(r => r.sensor_code)));
  const filteredReadings = sensorFilter ? readings.filter(r => r.sensor_code === sensorFilter) : readings;
  
  // Transform for charts: group by bucket_start, create columns per sensor
  const chartData = filteredReadings.reduce((acc, r) => {
    const key = r.bucket_start;
    if (!acc[key]) acc[key] = { period_start: key };
    acc[key][r.sensor_code] = agg === "avg" ? r.avg_value : agg === "min" ? r.min_value : agg === "max" ? r.max_value : r.sum_value;
    return acc;
  }, {} as Record<string, Record<string, number | string | null>>);

  const chartDataArray = Object.values(chartData).sort((a, b) => String(a.period_start).localeCompare(String(b.period_start))) as ChartDataPoint[];

  const availableSensors = selectedSensors.length > 0 ? selectedSensors : sensorCodes.slice(0, 3);
  const lines = availableSensors.map(code => {
    const sensor = sensors.find(s => s.sensor_type.code === code);
    return { key: code, name: sensor?.sensor_type.name || code, unit: sensor?.sensor_type.unit };
  });

  const handlePageChange = (page: number) => setPagination(p => ({ ...p, current_page: page }));
  const handlePerPageChange = (perPage: number) => setPagination(p => ({ ...p, per_page: perPage, current_page: 1 }));

  return (
    <div>
      <div className="flex justify-between items-center mb-4">
        <button onClick={onBack} className="btn-secondary">← Back</button>
        <h2>{device.name} ({device.device_code})</h2>
        <div className="status">
          <span className="dot" style={{ background: getQualityColor("GOOD") }} />
          <span>{device.status}</span>
        </div>
      </div>

      {error && <div className="alert-error">{error}</div>}

      <div className="controls-grid">
        <div className="control-group">
          <label>Interval</label>
          <select value={interval} onChange={e => { setInterval(e.target.value as any); setPagination(p=>({...p, current_page:1})); }}>
            <option value="raw">Raw</option>
            <option value="1m">1 Minute</option>
            <option value="1h">1 Hour</option>
            <option value="1d">1 Day</option>
          </select>
        </div>
        <div className="control-group">
          <label>Aggregation</label>
          <select value={agg} onChange={e => setAgg(e.target.value as any)}>
            <option value="avg">Average</option>
            <option value="min">Min</option>
            <option value="max">Max</option>
            <option value="sum">Sum</option>
          </select>
        </div>
        <div className="control-group">
          <label>From</label>
          <input type="date" value={fromDate} onChange={e => { setFromDate(e.target.value); setPagination(p=>({...p, current_page:1})); }} />
        </div>
        <div className="control-group">
          <label>To</label>
          <input type="date" value={toDate} onChange={e => { setToDate(e.target.value); setPagination(p=>({...p, current_page:1})); }} />
        </div>
        <div className="control-group">
          <label>Sensor</label>
          <select value={sensorFilter} onChange={e => { setSensorFilter(e.target.value); setPagination(p=>({...p, current_page:1})); }}>
            <option value="">All Sensors</option>
            {sensorCodes.map(code => <option key={code} value={code}>{code}</option>)}
          </select>
        </div>
        <div className="control-group">
          <label>Chart Type</label>
          <select value={chartType} onChange={e => setChartType(e.target.value as any)}>
            <option value="line">Line</option>
            <option value="dual">Dual Axis (Temp + Humidity)</option>
            <option value="bar">Bar</option>
            <option value="area">Area</option>
          </select>
        </div>
        <div className="control-group">
          <label>Sensors</label>
          <select multiple value={selectedSensors} onChange={e => setSelectedSensors(Array.from(e.target.selectedOptions).map(o => o.value))} size={4}>
            {sensorCodes.map(code => <option key={code} value={code}>{code}</option>)}
          </select>
        </div>
      </div>

      <div className="chart-section">
        <h3 className="panel-title">
          {chartType === "dual" ? "Temperature & Humidity" : `${lines[0]?.name || "Data"} (${interval})`}
        </h3>
        {loading ? (
          <div className="empty">Loading chart data…</div>
        ) : chartDataArray.length === 0 ? (
          <div className="empty">No data for selected range</div>
        ) : (
          <>
            {chartType === "dual" && (
              <DualAxisChart
                data={chartDataArray}
                leftLines={lines.filter(l => l.key === "temp_air")}
                rightLines={lines.filter(l => l.key === "humidity")}
              />
            )}
            {chartType === "line" && (
              <LineChartWidget data={chartDataArray} lines={lines} />
            )}
            {chartType === "bar" && (
              <BarChartWidget data={chartDataArray} bars={lines} />
            )}
            {chartType === "area" && (
              <AreaChartWidget data={chartDataArray} areas={lines} />
            )}
          </>
        )}
      </div>

      <div className="table-section">
        <h3 className="panel-title">Recent Readings</h3>
        {loading && <div className="empty">Loading…</div>}
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Time</th>
                <th>Sensor</th>
                <th>Min</th>
                <th>Max</th>
                <th>Avg</th>
                <th>Sum</th>
                <th>Samples</th>
                <th>Quality</th>
              </tr>
            </thead>
            <tbody>
              {filteredReadings.slice(0, 100).map((r, i) => (
                <tr key={i}>
                  <td>{fmtTime(r.bucket_start)}</td>
                  <td>{r.sensor_code}</td>
                  <td>{fmt(r.min_value)}</td>
                  <td>{fmt(r.max_value)}</td>
                  <td><strong>{fmt(r.avg_value)}</strong> {r.unit}</td>
                  <td>{fmt(r.sum_value)}</td>
                  <td>{r.sample_count}</td>
                  <td>
                    <span className="badge" style={{ background: `${getQualityColor("GOOD")}20`, color: getQualityColor("GOOD") }}>
                      GOOD
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination
          currentPage={pagination.current_page}
          totalPages={pagination.last_page}
          totalItems={pagination.total}
          perPage={pagination.per_page}
          onPageChange={handlePageChange}
          onPerPageChange={handlePerPageChange}
        />
      </div>
    </div>
  );
}