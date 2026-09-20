const API_BASE = process.env.NEXT_PUBLIC_API_URL || "http://localhost:8080";

async function fetchJson<T>(path: string, options?: RequestInit): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      "X-API-Key": process.env.NEXT_PUBLIC_API_KEY || "dev_demo_weather_station_2024",
      ...options?.headers,
    },
    cache: "no-store",
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({ message: res.statusText }));
    throw new Error(err.message || `HTTP ${res.status}`);
  }
  return res.json();
}

export const api = {
  dashboard: {
    get: () => fetchJson<import("@/app/types").DashboardData>("/api/v1/dashboard"),
  },

  devices: {
    list: (params?: { status?: string; page?: number; per_page?: number }) =>
      fetchJson<{ data: import("@/app/types").DeviceInfo[]; pagination: any }>(
        `/api/v1/devices?${new URLSearchParams(params as any).toString()}`
      ),
    get: (id: string) =>
      fetchJson<import("@/app/types").DeviceInfo>(`/api/v1/devices/${id}`),
    create: (data: any) =>
      fetchJson<import("@/app/types").DeviceInfo>("/api/v1/devices", {
        method: "POST",
        body: JSON.stringify(data),
      }),
    update: (id: string, data: any) =>
      fetchJson<import("@/app/types").DeviceInfo>(`/api/v1/devices/${id}`, {
        method: "PUT",
        body: JSON.stringify(data),
      }),
    delete: (id: string) =>
      fetchJson<void>(`/api/v1/devices/${id}`, { method: "DELETE" }),

    credentials: {
      list: (deviceId: string) =>
        fetchJson<{ data: import("@/app/types").DeviceCredential[] }>(
          `/api/v1/devices/${deviceId}/credentials`
        ),
      create: (deviceId: string, data?: { api_key?: string; secret?: string }) =>
        fetchJson<import("@/app/types").DeviceCredential>(
          `/api/v1/devices/${deviceId}/credentials`,
          { method: "POST", body: JSON.stringify(data) }
        ),
      rotate: (deviceId: string) =>
        fetchJson<import("@/app/types").DeviceCredential>(
          `/api/v1/devices/${deviceId}/credentials/rotate`,
          { method: "POST" }
        ),
      revoke: (deviceId: string, credentialId: string) =>
        fetchJson<void>(`/api/v1/devices/${deviceId}/credentials/${credentialId}`, {
          method: "DELETE",
        }),
    },

    status: {
      update: (deviceId: string, status: string, reason?: string) =>
        fetchJson<{
          device_id: string;
          previous_status: string;
          current_status: string;
          reason: string | null;
          changed_at: string;
        }>(`/api/v1/devices/${deviceId}/status`, {
          method: "POST",
          body: JSON.stringify({ status, reason }),
        }),
      history: (deviceId: string) =>
        fetchJson<{ data: import("@/app/types").DeviceStatusHistory[] }>(
          `/api/v1/devices/${deviceId}/status/history`
        ),
    },
  },

  health: {
    heartbeat: (deviceId: string, data: {
      battery_voltage?: number;
      rssi?: number;
      firmware_version?: string;
      uptime_seconds?: number;
    }) =>
      fetchJson<import("@/app/types").DeviceHealth>(
        `/api/v1/devices/${deviceId}/heartbeat`,
        { method: "POST", body: JSON.stringify(data) }
      ),
    get: (deviceId: string) =>
      fetchJson<import("@/app/types").DeviceHealth>(`/api/v1/devices/${deviceId}/health`),
    stale: (thresholdMinutes = 15, status?: string) =>
      fetchJson<import("@/app/types").StaleDevicesResponse>(
        `/api/v1/devices/monitoring/stale?threshold_minutes=${thresholdMinutes}${status ? `&status=${status}` : ""}`
      ),
  },
};