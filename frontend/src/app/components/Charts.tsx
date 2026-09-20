"use client";

import React from "react";
import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
  AreaChart,
  Area,
  BarChart,
  Bar,
  ComposedChart,
} from "recharts";
import { PALETTE, fmtTime, fmt } from "@/app/utils";

interface ChartDataPoint {
  period_start: string;
  [key: string]: string | number | null;
}

interface LineChartProps {
  data: ChartDataPoint[];
  lines: { key: string; name: string; unit?: string; color?: string }[];
  height?: number;
  xKey?: string;
}

export function LineChartWidget({
  data,
  lines,
  height = 300,
  xKey = "period_start",
}: LineChartProps) {
  if (!data.length) return <div className="empty">No data available</div>;

  const colors = lines.map((_, i) => PALETTE[i % PALETTE.length]);

  return (
    <div className="chart" style={{ height }}>
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data} margin={{ top: 5, right: 30, left: 20, bottom: 5 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#334155" />
          <XAxis
            dataKey={xKey}
            tickFormatter={fmtTime}
            tick={{ fill: "#94a3b8", fontSize: 11 }}
            axisLine={{ stroke: "#334155" }}
            tickLine={{ stroke: "#334155" }}
          />
          <YAxis
            tick={{ fill: "#94a3b8", fontSize: 11 }}
            axisLine={{ stroke: "#334155" }}
            tickLine={{ stroke: "#334155" }}
          />
          <Tooltip
            contentStyle={{
              background: "#1e293b",
              border: "1px solid #334155",
              borderRadius: "8px",
            }}
            labelFormatter={fmtTime}
            formatter={(value: number, name: string) => [fmt(value), name]}
          />
          <Legend
            wrapperStyle={{ paddingTop: "10px" }}
            formatter={(value: string) => value}
          />
          {lines.map((line, i) => (
            <Line
              key={line.key}
              type="monotone"
              dataKey={line.key}
              name={line.name}
              stroke={colors[i]}
              strokeWidth={2}
              dot={false}
              activeDot={{ r: 6 }}
              connectNulls={false}
            />
          ))}
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}

interface DualAxisChartProps {
  data: ChartDataPoint[];
  leftLines: { key: string; name: string; unit?: string }[];
  rightLines: { key: string; name: string; unit?: string }[];
  height?: number;
  xKey?: string;
}

export function DualAxisChart({
  data,
  leftLines,
  rightLines,
  height = 300,
  xKey = "period_start",
}: DualAxisChartProps) {
  if (!data.length) return <div className="empty">No data available</div>;

  const leftColors = leftLines.map((_, i) => PALETTE[i % PALETTE.length]);
  const rightColors = rightLines.map((_, i) => PALETTE[(i + leftLines.length) % PALETTE.length]);

  return (
    <div className="chart" style={{ height }}>
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart data={data} margin={{ top: 5, right: 30, left: 20, bottom: 5 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#334155" />
          <XAxis
            dataKey={xKey}
            tickFormatter={fmtTime}
            tick={{ fill: "#94a3b8", fontSize: 11 }}
            axisLine={{ stroke: "#334155" }}
            tickLine={{ stroke: "#334155" }}
          />
          <YAxis
            yAxisId="left"
            tick={{ fill: "#94a3b8", fontSize: 11 }}
            axisLine={{ stroke: "#334155" }}
            tickLine={{ stroke: "#334155" }}
            label={{ value: leftLines.map(l => l.unit).join(", "), angle: -90, position: "insideLeft", fill: "#94a3b8" }}
          />
          <YAxis
            yAxisId="right"
            orientation="right"
            tick={{ fill: "#94a3b8", fontSize: 11 }}
            axisLine={{ stroke: "#334155" }}
            tickLine={{ stroke: "#334155" }}
            label={{ value: rightLines.map(l => l.unit).join(", "), angle: 90, position: "insideRight", fill: "#94a3b8" }}
          />
          <Tooltip
            contentStyle={{
              background: "#1e293b",
              border: "1px solid #334155",
              borderRadius: "8px",
            }}
            labelFormatter={fmtTime}
            formatter={(value: number, name: string) => [fmt(value), name]}
          />
          <Legend wrapperStyle={{ paddingTop: "10px" }} />
          {leftLines.map((line, i) => (
            <Line
              key={`left-${line.key}`}
              yAxisId="left"
              type="monotone"
              dataKey={line.key}
              name={line.name}
              stroke={leftColors[i]}
              strokeWidth={2}
              dot={false}
              activeDot={{ r: 6 }}
              connectNulls={false}
            />
          ))}
          {rightLines.map((line, i) => (
            <Line
              key={`right-${line.key}`}
              yAxisId="right"
              type="monotone"
              dataKey={line.key}
              name={line.name}
              stroke={rightColors[i]}
              strokeWidth={2}
              strokeDasharray="5 5"
              dot={false}
              activeDot={{ r: 6 }}
              connectNulls={false}
            />
          ))}
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  );
}

interface BarChartProps {
  data: ChartDataPoint[];
  bars: { key: string; name: string; color?: string }[];
  height?: number;
  xKey?: string;
}

export function BarChartWidget({
  data,
  bars,
  height = 300,
  xKey = "period_start",
}: BarChartProps) {
  if (!data.length) return <div className="empty">No data available</div>;

  const colors = bars.map((_, i) => PALETTE[i % PALETTE.length]);

  return (
    <div className="chart" style={{ height }}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} margin={{ top: 5, right: 30, left: 20, bottom: 5 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#334155" />
          <XAxis
            dataKey={xKey}
            tickFormatter={fmtTime}
            tick={{ fill: "#94a3b8", fontSize: 11 }}
            axisLine={{ stroke: "#334155" }}
            tickLine={{ stroke: "#334155" }}
          />
          <YAxis
            tick={{ fill: "#94a3b8", fontSize: 11 }}
            axisLine={{ stroke: "#334155" }}
            tickLine={{ stroke: "#334155" }}
          />
          <Tooltip
            contentStyle={{
              background: "#1e293b",
              border: "1px solid #334155",
              borderRadius: "8px",
            }}
            labelFormatter={fmtTime}
            formatter={(value: number, name: string) => [fmt(value), name]}
          />
          <Legend wrapperStyle={{ paddingTop: "10px" }} />
          {bars.map((bar, i) => (
            <Bar
              key={bar.key}
              dataKey={bar.key}
              name={bar.name}
              fill={colors[i]}
              radius={[4, 4, 0, 0]}
            />
          ))}
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

interface AreaChartProps {
  data: ChartDataPoint[];
  areas: { key: string; name: string; color?: string }[];
  height?: number;
  xKey?: string;
}

export function AreaChartWidget({
  data,
  areas,
  height = 300,
  xKey = "period_start",
}: AreaChartProps) {
  if (!data.length) return <div className="empty">No data available</div>;

  const colors = areas.map((_, i) => PALETTE[i % PALETTE.length]);

  return (
    <div className="chart" style={{ height }}>
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={data} margin={{ top: 5, right: 30, left: 20, bottom: 5 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#334155" />
          <XAxis
            dataKey={xKey}
            tickFormatter={fmtTime}
            tick={{ fill: "#94a3b8", fontSize: 11 }}
            axisLine={{ stroke: "#334155" }}
            tickLine={{ stroke: "#334155" }}
          />
          <YAxis
            tick={{ fill: "#94a3b8", fontSize: 11 }}
            axisLine={{ stroke: "#334155" }}
            tickLine={{ stroke: "#334155" }}
          />
          <Tooltip
            contentStyle={{
              background: "#1e293b",
              border: "1px solid #334155",
              borderRadius: "8px",
            }}
            labelFormatter={fmtTime}
            formatter={(value: number, name: string) => [fmt(value), name]}
          />
          <Legend wrapperStyle={{ paddingTop: "10px" }} />
          {areas.map((area, i) => (
            <Area
              key={area.key}
              type="monotone"
              dataKey={area.key}
              name={area.name}
              stroke={colors[i]}
              fill={colors[i]}
              fillOpacity={0.3}
              strokeWidth={2}
            />
          ))}
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}