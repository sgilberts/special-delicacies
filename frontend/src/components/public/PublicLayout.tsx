import { Outlet } from "react-router-dom";
import PublicHeader from "./PublicHeader";
import PublicFooter from "./PublicFooter";
import MobileBottomNav from "./MobileBottomNav";

export default function PublicLayout() {
  return (
    <>
      <PublicHeader />
      <main className="public-main">
        <Outlet />
      </main>
      <PublicFooter />
      <MobileBottomNav />
    </>
  );
}
