import { useCallback, useEffect, useRef, useState } from 'react';
import jsQR from 'jsqr';

const SCAN_INTERVAL_MS = 120;
const SAMPLE_SIZE = 560; // px of the centred square passed to the decoder

function cameraErrorMessage(err) {
  if (!window.isSecureContext) return 'Kamera funguje jen přes HTTPS.';
  if (!navigator.mediaDevices?.getUserMedia) return 'Tento prohlížeč nepodporuje kameru.';
  if (err?.name === 'NotAllowedError') return 'Povolte přístup ke kameře v nastavení prohlížeče.';
  if (err?.name === 'NotFoundError' || err?.name === 'OverconstrainedError') return 'Kamera nebyla nalezena.';
  if (err?.name === 'NotReadableError') return 'Kameru používá jiná aplikace.';
  return 'Kameru se nepodařilo spustit.';
}

/**
 * Streams the rear camera into `videoRef` and calls `onCode(text)` once for
 * each detected QR code. Scanning pauses after a hit until `resume()` is called.
 */
export function useQrCamera(onCode, enabled) {
  const videoRef = useRef(null);
  const canvasRef = useRef(null);
  const streamRef = useRef(null);
  const pausedRef = useRef(false);
  const onCodeRef = useRef(onCode);
  const [status, setStatus] = useState('idle'); // idle | starting | scanning | paused | error
  const [error, setError] = useState(null);
  const [torch, setTorch] = useState({ supported: false, on: false });

  onCodeRef.current = onCode;

  const stop = useCallback(() => {
    streamRef.current?.getTracks().forEach((t) => t.stop());
    streamRef.current = null;
    if (videoRef.current) videoRef.current.srcObject = null;
  }, []);

  const start = useCallback(async () => {
    stop();
    setStatus('starting');
    setError(null);
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
      });
      streamRef.current = stream;
      const video = videoRef.current;
      video.srcObject = stream;
      await video.play();
      const track = stream.getVideoTracks()[0];
      const caps = track.getCapabilities?.() ?? {};
      setTorch({ supported: Boolean(caps.torch), on: false });
      setStatus(pausedRef.current ? 'paused' : 'scanning');
    } catch (err) {
      stop();
      setError(cameraErrorMessage(err));
      setStatus('error');
    }
  }, [stop]);

  // Start/stop with `enabled`; release the camera while the page is hidden.
  useEffect(() => {
    if (!enabled) return undefined;
    pausedRef.current = false;
    start();
    const onVisibility = () => (document.hidden ? stop() : start());
    document.addEventListener('visibilitychange', onVisibility);
    return () => {
      document.removeEventListener('visibilitychange', onVisibility);
      stop();
    };
  }, [enabled, start, stop]);

  // Decode loop.
  useEffect(() => {
    if (status !== 'scanning') return undefined;
    let frame;
    let last = 0;
    const canvas = canvasRef.current ?? (canvasRef.current = document.createElement('canvas'));
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    const tick = (time) => {
      frame = requestAnimationFrame(tick);
      const video = videoRef.current;
      if (time - last < SCAN_INTERVAL_MS || !video || video.readyState < 2) return;
      last = time;
      const vw = video.videoWidth;
      const vh = video.videoHeight;
      const side = Math.min(vw, vh);
      const size = Math.min(SAMPLE_SIZE, side);
      canvas.width = size;
      canvas.height = size;
      ctx.drawImage(video, (vw - side) / 2, (vh - side) / 2, side, side, 0, 0, size, size);
      const image = ctx.getImageData(0, 0, size, size);
      const result = jsQR(image.data, size, size, { inversionAttempts: 'dontInvert' });
      if (result?.data) {
        pausedRef.current = true;
        setStatus('paused');
        navigator.vibrate?.(80);
        onCodeRef.current(result.data);
      }
    };
    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [status]);

  const resume = useCallback(() => {
    pausedRef.current = false;
    if (streamRef.current) setStatus('scanning');
    else start();
  }, [start]);

  const toggleTorch = useCallback(async () => {
    const track = streamRef.current?.getVideoTracks()[0];
    if (!track) return;
    const on = !torch.on;
    try {
      await track.applyConstraints({ advanced: [{ torch: on }] });
      setTorch((t) => ({ ...t, on }));
    } catch {
      setTorch({ supported: false, on: false });
    }
  }, [torch.on]);

  return { videoRef, status, error, torch, toggleTorch, resume, retry: start };
}
