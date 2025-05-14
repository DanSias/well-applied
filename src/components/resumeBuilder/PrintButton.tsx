"use client";

import { useReactToPrint } from "react-to-print";
import { RefObject } from "react";

interface PrintButtonProps {
  targetRef: RefObject<HTMLDivElement | null>;
}

export default function PrintButton({ targetRef }: PrintButtonProps) {
  const handlePrint = useReactToPrint({
    // @ts-expect-error react-to-print typing mismatch
    content: () => targetRef.current,
    documentTitle: "Resume",
    removeAfterPrint: true,
  });

  return (
    <button
      onClick={handlePrint}
      className="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded shadow-md">
      Print Resume to PDF
    </button>
  );
}
