import { NextResponse } from "next/server";

export const dynamic = "force-dynamic";

// Server-side proxy: the browser cannot resolve "backend:8080", so the Next.js
// server forwards to the backend over the Docker internal network.
const backendBase =
  process.env.BACKEND_INTERNAL_URL ||
  process.env.NEXT_PUBLIC_API_URL ||
  "http://backend:8080";

export async function GET() {
  try {
    const res = await fetch(`${backendBase}/api/v1/dashboard`, {
      cache: "no-store",
      signal: AbortSignal.timeout(5000),
    });
    if (!res.ok) {
      return NextResponse.json(
        { error: `backend responded with ${res.status}` },
        { status: 502 },
      );
    }
    const data = await res.json();
    return NextResponse.json(data);
  } catch (err) {
    return NextResponse.json(
      { error: err instanceof Error ? err.message : "backend unreachable" },
      { status: 502 },
    );
  }
}