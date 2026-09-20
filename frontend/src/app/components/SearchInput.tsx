"use client";

import { useState, useEffect } from "react";

interface SearchInputProps {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  debounceMs?: number;
}

export function SearchInput({
  value,
  onChange,
  placeholder = "Search...",
  debounceMs = 300,
}: SearchInputProps) {
  const [localValue, setLocalValue] = useState(value);
  const [debouncedValue, setDebouncedValue] = useState(value);

  useEffect(() => {
    setLocalValue(value);
  }, [value]);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedValue(localValue);
      onChange(localValue);
    }, debounceMs);
    return () => clearTimeout(timer);
  }, [localValue, onChange, debounceMs]);

  return (
    <input
      type="text"
      value={localValue}
      onChange={(e) => setLocalValue(e.target.value)}
      placeholder={placeholder}
      className="search-input"
      aria-label={placeholder}
    />
  );
}