interface Props {
  eyebrow?: string;
  title: string;
  subtitle?: string;
  centered?: boolean;
}
export default function SectionHeading({ eyebrow, title, subtitle, centered = false }: Props) {
  return (
    <div className={centered ? "text-center" : ""}>
      {eyebrow && (
        <span className="block mb-2 text-xs font-bold tracking-widest uppercase"
          style={{ color: "var(--color-accent)" }}>
          {eyebrow}
        </span>
      )}
      <h2 className="text-3xl md:text-4xl font-bold mb-3"
        style={{ color: "var(--color-text)", letterSpacing: "-0.5px" }}>
        {title}
      </h2>
      {subtitle && (
        <p className="text-base md:text-lg" style={{ color: "var(--color-text-secondary)" }}>
          {subtitle}
        </p>
      )}
    </div>
  );
}
