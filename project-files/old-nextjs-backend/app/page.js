import { redirect } from 'next/navigation';

/** "/" serves the static homepage in /public. */
export default function Home() {
  redirect('/index.html');
}
