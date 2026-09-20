import React from "react";

export interface Location {
  id: string;
  name: string;
  latitude: number;
  longitude: number;
  altitude: number | null;
  country?: string;
}

export interface LatestReading {
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

export interface DeviceInfo {
  id: string;
  device_code: string;
  name: string;
  location: Location;
  status: 'provisioned' | 'active' | 'maintenance' | 'decommissioned';
  firmware_version: string;
  last_seen_at: string | null;
  rain_today_mm: number;
  latest: LatestReading | null;
}

export interface SeriesPoint {
  period_start: string;
  device_name: string;
  temperature_avg: number | null;
  humidity_avg: number | null;
  pressure_avg: number | null;
  windspeed_avg: number | null;
  wind_direction_avg: number | null;
  rain_total_mm: number;
}

export interface RecentRow {
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

export interface DashboardData {
  devices: DeviceInfo[];
  series: SeriesPoint[];
  recent: RecentRow[];
}

export interface DeviceCredential {
  id: string;
  api_key: string;
  secret?: string;
  last_used_at: string | null;
  revoked_at: string | null;
  created_at: string;
}

export interface DeviceStatusHistory {
  id: string;
  from_status: string | null;
  to_status: string;
  reason: string | null;
  changed_at: string;
}

export interface DeviceHealth {
  device_id: string;
  battery_voltage: number | null;
  rssi: number | null;
  firmware_version: string | null;
  last_heartbeat_at: string | null;
  uptime_seconds: number | null;
  is_stale: boolean;
  stale_minutes: number | null;
}

export interface StaleDevice {
  id: string;
  device_code: string;
  name: string;
  status: string;
  last_seen_at: string | null;
  stale_minutes: number | null;
  battery_voltage: number | null;
  rssi: number | null;
  firmware_version: string | null;
}

export interface StaleDevicesResponse {
  threshold_minutes: number;
  count: number;
  data: StaleDevice[];
}

export interface SensorType {
  id: string;
  code: string;
  name: string;
  unit: string;
  valid_min: number | null;
  valid_max: number | null;
  precision: number;
  created_at: string;
  updated_at: string;
}

export interface SensorTypeList {
  data: SensorType[];
  pagination: Pagination;
}

export interface Sensor {
  id: string;
  serial_number: string;
  sensor_type: SensorType;
  manufacturer: string;
  model: string;
  status: string;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface SensorList {
  data: Sensor[];
  pagination: Pagination;
}

export interface DeviceSensor {
  sensor_id: string;
  serial_number: string;
  sensor_type: SensorType;
  status: string;
  installed_at: string;
}

export interface DeviceSensorList {
  data: DeviceSensor[];
}

export interface Pagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface ReadingPoint {
  bucket_start: string;
  interval: string;
  sensor_id: string;
  sensor_code: string;
  sensor_name: string;
  unit: string;
  device_id: string;
  device_code: string;
  device_name: string;
  min_value: number | null;
  max_value: number | null;
  avg_value: number | null;
  sum_value: number | null;
  sample_count: number;
  quality_count: number;
}

export interface ReadingsResponse {
  data: ReadingPoint[];
  meta: {
    interval: string;
    from: string;
    to: string;
    limit: number;
    offset: number;
    total: number;
  };
}

export interface ReadingLatest {
  device_id: string;
  sensor_id: string;
  sensor_code: string;
  sensor_name: string;
  unit: string;
  device_time: string;
  raw_value: number;
  corrected_value: number | null;
  quality_flag: string;
}

export interface ChartDataPoint {
  period_start: string;
  [key: string]: string | number | null;
}