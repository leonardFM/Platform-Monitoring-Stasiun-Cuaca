"use client";

import { useState, useCallback, useEffect } from "react";
import { api } from "@/app/lib/api";
import { DashboardData, DeviceInfo } from "@/app/types";
import { Tabs } from "@/app/components/Tabs";
import { OverviewTab } from "@/app/components/OverviewTab";
import { DevicesTab } from "@/app/components/DevicesTab";
import { HealthTab } from "@/app/components/HealthTab";
import { fmtTime } from "@/app/utils";

export default function DashboardPage() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [devices, setDevices] = useState<DeviceInfo[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [updatedAt, setUpdatedAt] = useState<Date | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<"overview" | "devices" | "health">("overview");

  const refresh = useCallback(async () => {
    try {
      setLoading(true);
      const [dashboardRes, devicesRes] = await Promise.all([
        api.dashboard.get(),
        api.devices.list(),
      ]);
      setData(dashboardRes);
      setDevices(devicesRes.data);
      setError(null);
      setUpdatedAt(new Date());
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
    { id: "health" as const, label: "Health" },
  ];

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
        <DevicesTab onRefresh={refresh} />
      )}

      {activeTab === "health" && (
        <HealthTab devices={devices} />
      )}
    </div>
  );
}