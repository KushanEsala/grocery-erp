import type { Metadata } from "next";
import "./globals.css";
import { AuthProvider } from "@/lib/auth-context";
import { NumberInputWheelGuard } from "@/components/number-input-wheel-guard";

export const metadata: Metadata = {
  title: "Grocery ERP",
  description: "Grocery retail, stock, purchasing and cashier operations",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" className="h-full antialiased">
      <body className="min-h-full flex flex-col">
        <NumberInputWheelGuard />
        <AuthProvider>
          {children}
        </AuthProvider>
      </body>
    </html>
  );
}
