"use client";

import { useState, useEffect, useCallback } from "react";
import { api } from "@/app/lib/api";
import { DeviceInfo } from "@/app/types";
import { fmtTime, fmt, getStatusColor, getStatusBadgeClass } from "@/app/utils";
import { Pagination } from "@/app/components/Pagination";
import { SearchInput } from "@/app/components/SearchInput";
import { Modal } from "@/app/components/Modal";

interface DevicesTabProps {
  onRefresh: () => void;
  onSelectDevice?: (device: DeviceInfo) => void;
}

export function DevicesTab({ onRefresh, onSelectDevice }: DevicesTabProps) {
  const [devices, setDevices] = useState<DeviceInfo[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [pagination, setPagination] = useState({
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: 0,
  });
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [showModal, setShowModal] = useState(false);
  const [editingDevice, setEditingDevice] = useState<DeviceInfo | null>(null);
  const [formData, setFormData] = useState({
    device_code: "",
    name: "",
    location_id: "",
    status: "provisioned",
    firmware_version: "",
  });
  const [formError, setFormError] = useState("");

  const fetchDevices = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.devices.list({
        page: pagination.current_page,
        per_page: pagination.per_page,
        status: statusFilter || undefined,
        search: search || undefined,
      });
      setDevices(res.data);
      setPagination(res.pagination);
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load devices");
    } finally {
      setLoading(false);
    }
  }, [pagination.current_page, pagination.per_page, search, statusFilter]);

  useEffect(() => {
    fetchDevices();
  }, [fetchDevices]);

  const handlePageChange = (page: number) => {
    setPagination((prev) => ({ ...prev, current_page: page }));
  };

  const handlePerPageChange = (perPage: number) => {
    setPagination((prev) => ({ ...prev, per_page: perPage, current_page: 1 }));
  };

  const handleSearchChange = (value: string) => {
    setSearch(value);
    setPagination((prev) => ({ ...prev, current_page: 1 }));
  };

  const handleStatusChange = (status: string) => {
    setStatusFilter(status);
    setPagination((prev) => ({ ...prev, current_page: 1 }));
  };

  const openCreateModal = () => {
    setEditingDevice(null);
    setFormData({ device_code: "", name: "", location_id: "", status: "provisioned", firmware_version: "" });
    setFormError("");
    setShowModal(true);
  };

  const openEditModal = (device: DeviceInfo) => {
    setEditingDevice(device);
    setFormData({
      device_code: device.device_code,
      name: device.name,
      location_id: device.location.id,
      status: device.status,
      firmware_version: device.firmware_version,
    });
    setFormError("");
    setShowModal(true);
  };

  const closeModal = () => {
    setShowModal(false);
    setEditingDevice(null);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      if (editingDevice) {
        await api.devices.update(editingDevice.id, formData);
      } else {
        await api.devices.create(formData);
      }
      closeModal();
      onRefresh();
    } catch (err) {
      setFormError(err instanceof Error ? err.message : "Failed to save device");
    }
  };

  const handleDelete = async (device: DeviceInfo) => {
    if (!window.confirm(`Delete device "${device.name}"? This cannot be undone.`)) return;
    try {
      await api.devices.delete(device.id);
      onRefresh();
    } catch (err) {
      alert(err instanceof Error ? err.message : "Failed to delete device");
    }
  };

  if (loading && devices.length === 0) return <div className="empty">Loading devices…</div>;

  return (
    <div>
      <div className="flex justify-between items-center mb-4">
        <h2>Devices</h2>
        <button onClick={openCreateModal} className="btn-primary">
          + Add Device
        </button>
      </div>

      {error && <div className="alert-error">{error}</div>}

      <div className="filters">
        <SearchInput
          value={search}
          onChange={handleSearchChange}
          placeholder="Search by name, code..."
        />
        <select
          value={statusFilter}
          onChange={(e) => handleStatusChange(e.target.value)}
          className="filter-select"
        >
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="provisioned">Provisioned</option>
          <option value="maintenance">Maintenance</option>
          <option value="decommissioned">Decommissioned</option>
        </select>
      </div>

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Name / Code</th>
              <th>Location</th>
              <th>Status</th>
              <th>Firmware</th>
              <th>Last Seen</th>
              <th>Rain Today</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {devices.length === 0 && (
              <tr>
                <td colSpan={7} className="empty">No devices found</td>
              </tr>
            )}
            {devices.map((device) => (
              <tr key={device.id}>
                <td>
                  <div className="device-name">
                    <strong>{device.name}</strong>
                    <span className="device-code">{device.device_code}</span>
                  </div>
                </td>
                <td>
                  {device.location?.name || "—"}
                  {device.location?.country && <span className="text-muted">, {device.location.country}</span>}
                </td>
                <td>
                  <span
                    className={`badge ${getStatusBadgeClass(device.status)}`}
                    style={{ background: `${getStatusColor(device.status)}20`, color: getStatusColor(device.status) }}
                  >
                    {device.status}
                  </span>
                </td>
                <td>{device.firmware_version || "—"}</td>
                <td>{device.last_seen_at ? fmtTime(device.last_seen_at) : "—"}</td>
                <td>{fmt(device.rain_today_mm, 2)} mm</td>
                <td>
                  <div className="actions">
                    <button
                      onClick={() => onSelectDevice?.(device)}
                      className="btn-icon"
                      title="View Details"
                    >
                      👁
                    </button>
                    <button
                      onClick={() => openEditModal(device)}
                      className="btn-icon"
                      title="Edit"
                    >
                      ✏️
                    </button>
                    <button
                      onClick={() => handleDelete(device)}
                      className="btn-icon btn-danger"
                      title="Delete"
                    >
                      🗑
                    </button>
                  </div>
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

      <Modal isOpen={showModal} onClose={closeModal} title={editingDevice ? "Edit Device" : "Add Device"}>
        <form onSubmit={handleSubmit}>
          {formError && <div className="alert-error">{formError}</div>}
          <div className="form-group">
            <label>Device Code *</label>
            <input
              type="text"
              value={formData.device_code}
              onChange={(e) => setFormData({ ...formData, device_code: e.target.value })}
              required
              disabled={!!editingDevice}
              placeholder="e.g., WS-GRT-002"
            />
          </div>
          <div className="form-group">
            <label>Name *</label>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              required
              placeholder="e.g., Garut Barat"
            />
          </div>
          <div className="form-group">
            <label>Location ID *</label>
            <input
              type="text"
              value={formData.location_id}
              onChange={(e) => setFormData({ ...formData, location_id: e.target.value })}
              required
              placeholder="UUID of location"
            />
          </div>
          <div className="form-group">
            <label>Status</label>
            <select
              value={formData.status}
              onChange={(e) => setFormData({ ...formData, status: e.target.value })}
            >
              <option value="provisioned">Provisioned</option>
              <option value="active">Active</option>
              <option value="maintenance">Maintenance</option>
              <option value="decommissioned">Decommissioned</option>
            </select>
          </div>
          <div className="form-group">
            <label>Firmware Version</label>
            <input
              type="text"
              value={formData.firmware_version}
              onChange={(e) => setFormData({ ...formData, firmware_version: e.target.value })}
              placeholder="e.g., 1.4.2"
            />
          </div>
          <div className="modal-footer">
            <button type="button" onClick={closeModal} className="btn-secondary">Cancel</button>
            <button type="submit" className="btn-primary" disabled={loading}>
              {editingDevice ? "Save" : "Create"}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
}