"use client";

import { useState, useEffect } from "react";
import { api } from "@/app/lib/api";
import { DeviceInfo } from "@/app/types";
import { fmtTime, fmt } from "@/app/utils";

interface DevicesTabSimpleProps {
  onRefresh: () => void;
}

export function DevicesTabSimple({ onRefresh }: DevicesTabSimpleProps) {
  const [devices, setDevices] = useState([]);

  return (
    <div>
      <h2>Devices (Simple)</h2>
      <p>Devices tab - simplified version</p>
    </div>
  );
}