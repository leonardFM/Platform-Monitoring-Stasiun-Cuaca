"use client";

import { useState, useCallback, useEffect, useRef } from "react";
import { api } from "@/app/lib/api";
import { DashboardData, DeviceInfo, SensorType, Sensor, DeviceSensor, ReadingPoint } from "@/app/types";
import { Tabs } from "@/app/components/Tabs";
import { OverviewTab } from "@/app/components/OverviewTab";
import { DevicesTab } from "@/app/components/DevicesTab";
import { SensorsTab } from "@/app/components/SensorsTab";
import { DeviceDetailTab } from "@/app/components/DeviceDetailTab";
import { HealthTab } from "@/app/components/HealthTab";
import { fmtTime } from "@/app/utils";

export default function DashboardPage() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [devices, setDevices] = useState<DeviceInfo[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [updatedAt, setUpdatedAt] = useState<Date | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<"overview" | "devices" | "sensors" | "detail" | "health">("overview");
  const [selectedDevice, setSelectedDevice] = useState<DeviceInfo | null>(null);
  const refreshCountRef = useRef(0);

  const refresh = useCallback(async () => {
    try {
      setLoading(true);
      const [dashboardRes, devicesRes] = await Promise.all([
        api.dashboard.get(),
        api.devices.list({ per_page: 100 }),
      ]);
      setData(dashboardRes);
      setDevices(devicesRes.data);
      setError(null);
      setUpdatedAt(new Date());
      refreshCountRef.current += 1;
    } catch (err) {
      setError(err instanceof Error ? err.message : "failed to load data");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    refresh();
    const interval = window.setInterval(refresh, 30_000);
    return () => window.clearInterval(interval);
  }, [refresh]);

  const tabs = [
    { id: "overview" as const, label: "Overview" },
    { id: "devices" as const, label: "Devices" },
    { id: "sensors" as const, label: "Sensors" },
    { id: "health" as const, label: "Health" },
  ];

  return (
    <div className="container">
      <header className="header">
        <h1>Weather Station Monitor</h1>
        <div className="header-right">
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
                  {refreshCountRef.current > 0 && <span className="refresh-count"> (#{refreshCountRef.current})</span>}
                </span>
              </>
            )}
          </div>
          <button onClick={refresh} disabled={loading} className="btn-primary">
            {loading ? "Refreshing..." : "Refresh"}
          </button>
        </div>
      </header>

      <Tabs tabs={tabs} activeTab={activeTab} onChange={setActiveTab} />

      {activeTab === "overview" && (
        <OverviewTab
          data={data}
          error={error}
          updatedAt={updatedAt}
          onRefresh={refresh}
        />
      )}

      {activeTab === "devices" && (
        <DevicesTab
          onRefresh={refresh}
          onSelectDevice={(device) => {
            setSelectedDevice(device);
            setActiveTab("detail");
          }}
        />
      )}

      {activeTab === "sensors" && (
        <SensorsTab onRefresh={refresh} />
      )}

      {activeTab === "detail" && selectedDevice && (
        <DeviceDetailTab
          device={selectedDevice}
          onBack={() => setActiveTab("devices")}
        />
      )}

      {activeTab === "health" && (
        <HealthTab devices={devices} />
      )}
    </div>
  );
}