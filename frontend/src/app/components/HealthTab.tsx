"use client";

import React, { useState, useEffect } from "react";
import { api } from "@/app/lib/api";
import type { DeviceInfo } from "@/app/types";
import { fmtTime, fmt } from "@/app/utils";

interface HealthTabProps {
  devices: any[];
}

export function HealthTab({ devices }: HealthTabProps) {
  const [health, setHealth] = useState<Record<string, any>>({});
  const [staleDevices, setStaleDevices] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [staleLoading, setStaleLoading] = useState(true);
  const [threshold, setThreshold] = useState(15);

  const fetchAllHealth = async () => {
    setLoading(true);
    try {
      const healthMap: Record<string, any> = {};
      for (const device of devices) {
        try {
          const h = await api.health.get(device.id);
          healthMap[device.id] = h;
        } catch {
          healthMap[device.id] = { device_id: device.id };
        }
      }
      setHealth(healthMap);
    } finally {
      setLoading(false);
    }
  };

  const fetchStale = async () => {
    setStaleLoading(true);
    try {
      const res = await api.health.stale(threshold);
      setStaleDevices(res.data);
    } finally {
      setStaleLoading(false);
    }
  };

  useEffect(() => {
    fetchAllHealth();
    fetchStale();
  }, [devices, threshold]);

  if (loading) return <div className="empty">Loading health data…</div>;

  return (
    <div>
      <h2>Device Health</h2>
      <p>Health tab - simplified version</p>
    </div>
  );
}
