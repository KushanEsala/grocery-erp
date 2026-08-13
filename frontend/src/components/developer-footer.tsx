interface DeveloperFooterProps {
  inverse?: boolean;
}

export function DeveloperFooter({ inverse = false }: DeveloperFooterProps) {
  const year = new Date().getFullYear();
  const textColor = inverse ? 'text-emerald-50/70' : 'text-slate-500';
  const linkColor = inverse
    ? 'text-emerald-100 hover:text-white'
    : 'text-[#237a55] hover:text-[#174a38]';

  return (
    <footer
      className={`flex flex-col items-center justify-center gap-1.5 text-center text-[10px] leading-4 sm:flex-row sm:gap-2.5 ${textColor}`}
    >
      <p>
        <span aria-hidden="true">&copy;</span> {year}{' '}
        <span className="font-semibold">Kushan Esala</span>, Developer. All rights reserved.
      </p>
      <span className={`hidden h-3 w-px sm:block ${inverse ? 'bg-white/15' : 'bg-slate-300'}`} aria-hidden="true" />
      <div className="flex flex-wrap items-center justify-center gap-x-2.5 gap-y-1">
        <a
          href="mailto:kushanesalakck@gmail.com"
          aria-label="Email Kushan Esala at kushanesalakck@gmail.com"
          className={`font-medium underline-offset-2 transition hover:underline ${linkColor}`}
        >
          kushanesalakck@gmail.com
        </a>
        <span aria-hidden="true">&bull;</span>
        <a
          href="tel:+94754628289"
          aria-label="Call Kushan Esala at +94 75 462 8289"
          className={`font-medium underline-offset-2 transition hover:underline ${linkColor}`}
        >
          +94 75 462 8289
        </a>
      </div>
    </footer>
  );
}
