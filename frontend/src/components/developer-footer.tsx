import { Mail, Phone } from 'lucide-react';

interface DeveloperFooterProps {
  inverse?: boolean;
}

export function DeveloperFooter({ inverse = false }: DeveloperFooterProps) {
  const year = new Date().getFullYear();
  const textColor = inverse ? 'text-emerald-50/70' : 'text-slate-500';
  const linkColor = inverse
    ? 'border-white/10 bg-white/[0.06] text-emerald-100 hover:bg-white/10 hover:text-white'
    : 'border-[#dce5de] bg-white text-[#237a55] hover:border-[#b9d7c5] hover:bg-[#eff9f2]';

  return (
    <footer
      className={`flex flex-col items-center justify-center gap-3 text-center text-xs sm:flex-row ${textColor}`}
    >
      <p>
        <span aria-hidden="true">©</span> {year}{' '}
        <span className="font-semibold">Kushan Esala</span>, Developer. All rights reserved.
      </p>
      <span className={`hidden h-4 w-px sm:block ${inverse ? 'bg-white/15' : 'bg-slate-300'}`} aria-hidden="true" />
      <div className="flex items-center gap-2">
        <a
          href="mailto:kushanesalakck@gmail.com"
          aria-label="Email Kushan Esala at kushanesalakck@gmail.com"
          title="kushanesalakck@gmail.com"
          className={`inline-flex h-8 w-8 items-center justify-center rounded-full border transition ${linkColor}`}
        >
          <Mail className="h-3.5 w-3.5" aria-hidden="true" />
        </a>
        <a
          href="tel:+94754628289"
          aria-label="Call Kushan Esala at +94 75 462 8289"
          title="+94 75 462 8289"
          className={`inline-flex h-8 w-8 items-center justify-center rounded-full border transition ${linkColor}`}
        >
          <Phone className="h-3.5 w-3.5" aria-hidden="true" />
        </a>
      </div>
    </footer>
  );
}
