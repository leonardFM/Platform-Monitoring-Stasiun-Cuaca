"use client";

import { useState, useEffect, useCallback } from "react";
import { api } from "@/app/lib/api";
import { SensorType, Sensor, SensorTypeList, SensorList, Pagination as PaginationType } from "@/app/types";
import { fmtTime, getStatusBadgeClass } from "@/app/utils";
import { Pagination } from "@/app/components/Pagination";
import { SearchInput } from "@/app/components/SearchInput";
import { Modal } from "@/app/components/Modal";

export function SensorsTab({ onRefresh }: { onRefresh: () => void }) {
  const [sensorTypes, setSensorTypes] = useState<SensorType[]>([]);
  const [sensors, setSensors] = useState<Sensor[]>([]);
  const [loadingTypes, setLoadingTypes] = useState(true);
  const [loadingSensors, setLoadingSensors] = useState(true);
  const [activeTab, setActiveTab] = useState<"types" | "sensors">("types");
  
  const [typePagination, setTypePagination] = useState<PaginationType>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  const [sensorPagination, setSensorPagination] = useState<PaginationType>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  const [typeSearch, setTypeSearch] = useState("");
  const [sensorSearch, setSensorSearch] = useState("");
  const [sensorTypeFilter, setSensorTypeFilter] = useState("");

  const [showTypeModal, setShowTypeModal] = useState(false);
  const [editingType, setEditingType] = useState<SensorType | null>(null);
  const [typeForm, setTypeForm] = useState({ code: "", name: "", unit: "", valid_min: "", valid_max: "", precision: "" });

  const [showSensorModal, setShowSensorModal] = useState(false);
  const [editingSensor, setEditingSensor] = useState<Sensor | null>(null);
  const [sensorForm, setSensorForm] = useState({ serial_number: "", sensor_type_id: "", manufacturer: "", model: "", status: "active" });

  const fetchTypes = useCallback(async () => {
    setLoadingTypes(true);
    try {
      const res = await api.sensorTypes.list({ page: typePagination.current_page, per_page: typePagination.per_page });
      setSensorTypes(res.data);
      setTypePagination(res.pagination);
    } catch (err) {
      console.error(err);
    } finally {
      setLoadingTypes(false);
    }
  }, [typePagination.current_page, typePagination.per_page]);

  const fetchSensors = useCallback(async () => {
    setLoadingSensors(true);
    try {
      const res = await api.sensors.list({ page: sensorPagination.current_page, per_page: sensorPagination.per_page, sensor_type_id: sensorTypeFilter || undefined });
      setSensors(res.data);
      setSensorPagination(res.pagination);
    } catch (err) {
      console.error(err);
    } finally {
      setLoadingSensors(false);
    }
  }, [sensorPagination.current_page, sensorPagination.per_page, sensorTypeFilter]);

  useEffect(() => { fetchTypes(); }, [fetchTypes]);
  useEffect(() => { fetchSensors(); }, [fetchSensors]);

  // Type handlers
  const openCreateType = () => { setEditingType(null); setTypeForm({ code: "", name: "", unit: "", valid_min: "", valid_max: "", precision: "" }); setShowTypeModal(true); };
  const openEditType = (t: SensorType) => { setEditingType(t); setTypeForm({ code: t.code, name: t.name, unit: t.unit, valid_min: String(t.valid_min ?? ""), valid_max: String(t.valid_max ?? ""), precision: String(t.precision) }); setShowTypeModal(true); };
  const closeTypeModal = () => { setShowTypeModal(false); setEditingType(null); };
  const submitType = async (e: React.FormEvent) => { e.preventDefault(); try { const data = { code: typeForm.code, name: typeForm.name, unit: typeForm.unit, valid_min: typeForm.valid_min ? Number(typeForm.valid_min) : null, valid_max: typeForm.valid_max ? Number(typeForm.valid_max) : null, precision: Number(typeForm.precision) }; if (editingType) await api.sensorTypes.update(editingType.id, data); else await api.sensorTypes.create(data); closeTypeModal(); fetchTypes(); } catch (err) { alert(err instanceof Error ? err.message : "Failed"); } };

  // Sensor handlers
  const openCreateSensor = () => { setEditingSensor(null); setSensorForm({ serial_number: "", sensor_type_id: "", manufacturer: "", model: "", status: "active" }); setShowSensorModal(true); };
  const openEditSensor = (s: Sensor) => { setEditingSensor(s); setSensorForm({ serial_number: s.serial_number, sensor_type_id: s.sensor_type.id, manufacturer: s.manufacturer, model: s.model, status: s.status }); setShowSensorModal(true); };
  const closeSensorModal = () => { setShowSensorModal(false); setEditingSensor(null); };
  const submitSensor = async (e: React.FormEvent) => { e.preventDefault(); try { const data = { serial_number: sensorForm.serial_number, sensor_type_id: sensorForm.sensor_type_id, manufacturer: sensorForm.manufacturer, model: sensorForm.model, status: sensorForm.status }; if (editingSensor) await api.sensors.update(editingSensor.id, data); else await api.sensors.create(data); closeSensorModal(); fetchSensors(); } catch (err) { alert(err instanceof Error ? err.message : "Failed"); } };

  if (activeTab === "types" && loadingTypes && sensorTypes.length === 0) return <div className="empty">Loading sensor types…</div>;
  if (activeTab === "sensors" && loadingSensors && sensors.length === 0) return <div className="empty">Loading sensors…</div>;

  return (
    <div>
      <div className="flex justify-between items-center mb-4">
        <div className="tabs-inline" role="tablist">
          <button role="tab" aria-selected={activeTab === "types"} onClick={() => setActiveTab("types")} className={activeTab === "types" ? "active" : ""}>Sensor Types</button>
          <button role="tab" aria-selected={activeTab === "sensors"} onClick={() => setActiveTab("sensors")} className={activeTab === "sensors" ? "active" : ""}>Sensors</button>
        </div>
        {activeTab === "types" && <button onClick={openCreateType} className="btn-primary">+ Add Type</button>}
        {activeTab === "sensors" && <button onClick={openCreateSensor} className="btn-primary">+ Add Sensor</button>}
      </div>

      {activeTab === "types" && (
        <>
          <SearchInput value={typeSearch} onChange={(v) => { setTypeSearch(v); setTypePagination(p => ({...p, current_page: 1})); }} placeholder="Search types..." />
          <div className="table-wrap">
            <table>
              <thead><tr><th>Code</th><th>Name</th><th>Unit</th><th>Range</th><th>Precision</th><th>Actions</th></tr></thead>
              <tbody>
                {sensorTypes.map(t => (
                  <tr key={t.id}>
                    <td><code>{t.code}</code></td>
                    <td>{t.name}</td>
                    <td>{t.unit}</td>
                    <td>{t.valid_min !== null ? t.valid_min : "–"} to {t.valid_max !== null ? t.valid_max : "∞"}</td>
                    <td>{t.precision}</td>
                    <td>
                      <button onClick={() => openEditType(t)} className="btn-icon" title="Edit">✏️</button>
                      <button onClick={() => { if(confirm(`Delete ${t.code}?`)) { api.sensorTypes.delete(t.id).then(fetchTypes); } }} className="btn-icon btn-danger" title="Delete">🗑</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination currentPage={typePagination.current_page} totalPages={typePagination.last_page} totalItems={typePagination.total} perPage={typePagination.per_page} onPageChange={page => setTypePagination(prev => ({...prev, current_page: page}))} onPerPageChange={perPage => setTypePagination(prev => ({...prev, per_page: perPage, current_page: 1}))} />
        </>
      )}

      {activeTab === "sensors" && (
        <>
          <div className="filters">
            <SearchInput value={sensorSearch} onChange={(v) => { setSensorSearch(v); setSensorPagination(p=>({...p, current_page:1})); }} placeholder="Search sensors..." />
            <select value={sensorTypeFilter} onChange={(e) => { setSensorTypeFilter(e.target.value); setSensorPagination(p=>({...p, current_page:1})); }} className="filter-select">
              <option value="">All Types</option>
              {sensorTypes.map(t => <option key={t.id} value={t.id}>{t.code} - {t.name}</option>)}
            </select>
          </div>
          <div className="table-wrap">
            <table>
              <thead><tr><th>Serial</th><th>Type</th><th>Manufacturer</th><th>Model</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody>
                {sensors.map(s => (
                  <tr key={s.id}>
                    <td><code>{s.serial_number}</code></td>
                    <td>{s.sensor_type.code} ({s.sensor_type.unit})</td>
                    <td>{s.manufacturer}</td>
                    <td>{s.model}</td>
                    <td><span className={`badge ${getStatusBadgeClass(s.status)}`}>{s.status}</span></td>
                    <td>
                      <button onClick={() => openEditSensor(s)} className="btn-icon" title="Edit">✏️</button>
                      <button onClick={() => { if(confirm(`Delete ${s.serial_number}?`)) { api.sensors.delete(s.id).then(fetchSensors); } }} className="btn-icon btn-danger" title="Delete">🗑</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination currentPage={sensorPagination.current_page} totalPages={sensorPagination.last_page} totalItems={sensorPagination.total} perPage={sensorPagination.per_page} onPageChange={page => setSensorPagination(prev => ({...prev, current_page: page}))} onPerPageChange={perPage => setSensorPagination(prev => ({...prev, per_page: perPage, current_page: 1}))} />
        </>
      )}

      <Modal isOpen={showTypeModal} onClose={closeTypeModal} title={editingType ? "Edit Sensor Type" : "Add Sensor Type"}>
        <form onSubmit={submitType}>
          <div className="form-group"><label>Code *</label><input type="text" value={typeForm.code} onChange={e=>setTypeForm({...typeForm, code:e.target.value})} required disabled={!!editingType} placeholder="e.g., temp_air" /></div>
          <div className="form-group"><label>Name *</label><input type="text" value={typeForm.name} onChange={e=>setTypeForm({...typeForm, name:e.target.value})} required placeholder="e.g., Suhu Udara" /></div>
          <div className="form-group"><label>Unit *</label><input type="text" value={typeForm.unit} onChange={e=>setTypeForm({...typeForm, unit:e.target.value})} required placeholder="e.g., °C" /></div>
          <div className="form-row"><div className="form-group"><label>Min</label><input type="number" step="any" value={typeForm.valid_min} onChange={e=>setTypeForm({...typeForm, valid_min:e.target.value})} placeholder="-60" /></div><div className="form-group"><label>Max</label><input type="number" step="any" value={typeForm.valid_max} onChange={e=>setTypeForm({...typeForm, valid_max:e.target.value})} placeholder="100" /></div><div className="form-group"><label>Precision</label><input type="number" step="any" value={typeForm.precision} onChange={e=>setTypeForm({...typeForm, precision:e.target.value})} required placeholder="0.1" /></div></div>
          <div className="modal-footer"><button type="button" onClick={closeTypeModal} className="btn-secondary">Cancel</button><button type="submit" className="btn-primary">{editingType ? "Save" : "Create"}</button></div>
        </form>
      </Modal>

      <Modal isOpen={showSensorModal} onClose={closeSensorModal} title={editingSensor ? "Edit Sensor" : "Add Sensor"}>
        <form onSubmit={submitSensor}>
          <div className="form-group"><label>Serial Number *</label><input type="text" value={sensorForm.serial_number} onChange={e=>setSensorForm({...sensorForm, serial_number:e.target.value})} required disabled={!!editingSensor} placeholder="e.g., WS-TEMP-001" /></div>
          <div className="form-group"><label>Sensor Type *</label><select value={sensorForm.sensor_type_id} onChange={e=>setSensorForm({...sensorForm, sensor_type_id:e.target.value})} required><option value="">Select type</option>{sensorTypes.map(t=><option key={t.id} value={t.id}>{t.code} - {t.name} ({t.unit})</option>)}</select></div>
          <div className="form-row"><div className="form-group"><label>Manufacturer</label><input type="text" value={sensorForm.manufacturer} onChange={e=>setSensorForm({...sensorForm, manufacturer:e.target.value})} placeholder="Luweis WS" /></div><div className="form-group"><label>Model</label><input type="text" value={sensorForm.model} onChange={e=>setSensorForm({...sensorForm, model:e.target.value})} placeholder="GRT-100" /></div></div>
          <div className="form-group"><label>Status</label><select value={sensorForm.status} onChange={e=>setSensorForm({...sensorForm, status:e.target.value})}><option value="active">Active</option><option value="inactive">Inactive</option><option value="maintenance">Maintenance</option></select></div>
          <div className="modal-footer"><button type="button" onClick={closeSensorModal} className="btn-secondary">Cancel</button><button type="submit" className="btn-primary">{editingSensor ? "Save" : "Create"}</button></div>
        </form>
      </Modal>
    </div>
  );
}