"use client";

import React, { useState, useEffect } from "react";
import { api } from "@/app/lib/api";
import type { DeviceInfo } from "@/app/types";
import { fmtTime, fmt } from "@/app/utils";

interface DevicesTabProps {
  onRefresh: () => void;
}

export function DevicesTab({ onRefresh }: DevicesTabProps) {
  const [devices, setDevices] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.devices.list().then(res => {
      setDevices(res.data);
      setLoading(false);
    });
  }, []);

  if (loading) return <div className="empty">Loading devices…</div>;

  return (
    <div>
      <h2>Devices</h2>
      <ul>
        {devices.map(d => <li key={d.id}>{d.name} - {d.status}</li>)}
      </ul>
    </div>
  );
}
