"use client";

import { useState, useEffect } from "react";
import { api } from "@/app/lib/api";
import { DeviceHealth, StaleDevicesResponse, StaleDevice, DeviceInfo } from "@/app/types";
import { fmtTime, fmt } from "@/app/utils";
import { Modal } from "./Modal";

interface HealthTabSimpleProps {
  devices: DeviceInfo[];
}

export function HealthTabSimple({ devices }: HealthTabSimpleProps) {
  const [health, setHealth] = useState<Record<string, DeviceHealth>>({});
  const [staleDevices, setStaleDevices] = useState<StaleDevice[]>([]);
  const [loading, setLoading] = useState(true);
  const [staleLoading, setStaleLoading] = useState(true);
  const [threshold, setThreshold] = useState(15);

  const fetchAllHealth = async () => {
    const healthMap: Record<string, DeviceHealth> = {};
    for (const device of devices) {
      try {
        const h = await api.health.get(device.id);
        healthMap[device.id] = h;
      } catch {
        healthMap[device.id] = { device_id: device.id } as DeviceHealth;
      }
    }
    setHealth(healthMap);
  };

  const fetchStale = async () => {
    try {
      const res = await api.health.stale(threshold);
      setStaleDevices(res.data);
    } finally {
      setStaleLoading(false);
    }
  };

  return (
    <div>
      <h2>Device Health</h2>
      <p>Health tab - simplified version</p>
    </div>
  );
}