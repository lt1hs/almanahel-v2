import { redirect } from "next/navigation";

/** `/` has no locale segment — send users to the default locale. */
export default function RootPage() {
  redirect("/fa/");
}
