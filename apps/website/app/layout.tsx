import "./globals.css";
import type { Metadata } from "next";
export const metadata: Metadata = {
  title: "Cape Town Movers | Moving made clear",
  description:
    "Household moves, office relocations and general goods transport in Cape Town and surrounding areas.",
};
export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en-ZA">
      <body>{children}</body>
    </html>
  );
}
