import { useEffect } from "react";
import { Link } from "react-router-dom";
import { UtensilsCrossed, Heart, Award, Users, User, ArrowRight } from "lucide-react";
import SectionHeading from "@/components/public/SectionHeading";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";
import heroBg from "@/assets/hero.png";

export default function AboutPage() {
  const { content } = useRestaurantContent();
  useEffect(() => {
    document.title = "About Us | Special Delicacies";
  }, []);

  return (
    <div className="flex flex-col w-full">
      {/* 1. PAGE HERO */}
      <section
        className="relative w-full flex items-center justify-center text-center px-4 overflow-hidden"
        style={{
          minHeight: "40vh",
          backgroundImage: `linear-gradient(to bottom, rgba(48, 32, 25, 0.65) 0%, rgba(48, 32, 25, 0.85) 100%), url(${heroBg})`,
          backgroundSize: "cover",
          backgroundPosition: "center",
          backgroundRepeat: "no-repeat",
        }}
      >
        <div className="max-w-2xl mx-auto py-12 flex flex-col items-center">
          <span
            className="text-xs md:text-sm font-bold tracking-widest uppercase mb-2 inline-block"
            style={{ color: "var(--brand-gold)" }}
          >
            WHO WE ARE
          </span>
          <h1 className="text-3xl sm:text-4xl md:text-5xl font-extrabold text-white tracking-tight mb-3">
            About Us
          </h1>
          <p className="text-base sm:text-lg text-white/90 font-normal">
            Our story, our passion, our food.
          </p>
        </div>
      </section>

      {/* 2. OUR STORY SECTION */}
      <section className="section-padding bg-[var(--color-background)]">
        <div className="section-container">
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <div>
              <SectionHeading
                eyebrow="OUR JOURNEY"
                title="Our Story"
                subtitle="Rooted in passion, driven by excellence."
              />
              <div className="mt-6 space-y-4 text-base md:text-lg leading-relaxed" style={{ color: "var(--color-text-secondary)" }}>
                {content.about_story ? (
                  content.about_story.split("\n\n").map((para, i) => (
                    <p key={i}>{para}</p>
                  ))
                ) : (
                  <>
                    <p>
                      Special Delicacies was founded with a singular conviction: that exceptional food brings communities together. What began as a humble dream has grown into a destination where guests can delight in authentic, thoughtfully prepared meals made from the freshest local and imported ingredients.
                    </p>
                    <p>
                      Every recipe in our kitchen honors culinary traditions while embracing modern flavors. From our signature slow-simmered sauces to our freshly baked accompaniments, our dedicated culinary team pours dedication into every single plate served.
                    </p>
                  </>
                )}
              </div>
            </div>

            <div className="w-full aspect-[4/3] rounded-2xl bg-[var(--color-surface)] border border-[var(--color-border)] shadow-sm flex flex-col items-center justify-center text-center p-8">
              <div
                className="w-16 h-16 rounded-full flex items-center justify-center mb-4"
                style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
              >
                <Users size={32} />
              </div>
              <h3 className="font-semibold text-lg" style={{ color: "var(--color-text)" }}>
                Kitchen & Hospitality Team [Placeholder]
              </h3>
              <p className="text-sm mt-2 max-w-sm" style={{ color: "var(--color-text-secondary)" }}>
                A passionate group of culinary professionals committed to serving perfection.
              </p>
            </div>
          </div>
        </div>
      </section>

      {/* 3. MISSION & VALUES */}
      <section className="section-padding bg-[var(--color-surface)] border-y border-[var(--color-border)]">
        <div className="section-container">
          <SectionHeading
            eyebrow="CORE BELIEFS"
            title="Our Mission & Values"
            subtitle="The fundamental principles that guide everything we prepare."
            centered
          />
          <div className="mt-12 grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8">
            <div className="pub-card p-6 md:p-8 flex flex-col items-center text-center transition-transform hover:-translate-y-1">
              <div
                className="w-14 h-14 rounded-full flex items-center justify-center mb-5 shrink-0"
                style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
              >
                <UtensilsCrossed size={28} />
              </div>
              <h3 className="text-xl font-bold mb-3" style={{ color: "var(--color-text)" }}>
                Quality Food
              </h3>
              <p className="text-sm md:text-base leading-relaxed" style={{ color: "var(--color-text-secondary)" }}>
                We are committed to serving only the finest quality dishes, made from fresh ingredients and prepared with uncompromising integrity.
              </p>
            </div>

            <div className="pub-card p-6 md:p-8 flex flex-col items-center text-center transition-transform hover:-translate-y-1">
              <div
                className="w-14 h-14 rounded-full flex items-center justify-center mb-5 shrink-0"
                style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
              >
                <Heart size={28} />
              </div>
              <h3 className="text-xl font-bold mb-3" style={{ color: "var(--color-text)" }}>
                Community First
              </h3>
              <p className="text-sm md:text-base leading-relaxed" style={{ color: "var(--color-text-secondary)" }}>
                Special Delicacies is more than a restaurant — it's a gathering place that fosters friendships, celebrations, and enduring community bonds.
              </p>
            </div>

            <div className="pub-card p-6 md:p-8 flex flex-col items-center text-center transition-transform hover:-translate-y-1">
              <div
                className="w-14 h-14 rounded-full flex items-center justify-center mb-5 shrink-0"
                style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
              >
                <Award size={28} />
              </div>
              <h3 className="text-xl font-bold mb-3" style={{ color: "var(--color-text)" }}>
                Excellence Always
              </h3>
              <p className="text-sm md:text-base leading-relaxed" style={{ color: "var(--color-text-secondary)" }}>
                We hold ourselves to the highest standards in hygiene, food safety, service efficiency, and culinary presentation.
              </p>
            </div>
          </div>
        </div>
      </section>

      {/* 4. TEAM SECTION */}
      <section className="section-padding bg-[var(--color-background)]">
        <div className="section-container">
          <SectionHeading
            eyebrow="LEADERSHIP"
            title="Meet the Team"
            subtitle="The talented minds creating your culinary delights."
            centered
          />
          <div className="mt-12 grid grid-cols-1 md:grid-cols-3 gap-6">
            {[
              {
                role: "Executive Head Chef",
                name: "[Chef Name — Placeholder]",
                desc: "Bringing decades of gourmet culinary craftsmanship and recipe innovation to Special Delicacies.",
              },
              {
                role: "General Manager",
                name: "[Manager Name — Placeholder]",
                desc: "Ensuring every patron experiences warm, world-class hospitality and seamless service.",
              },
              {
                role: "Head of Pastry & Desserts",
                name: "[Pastry Chef Name — Placeholder]",
                desc: "Crafting decadent sweet treats and signature artisanal baked items made fresh daily.",
              },
            ].map((member, index) => (
              <div
                key={index}
                className="pub-card p-6 flex flex-col items-center text-center transition-transform hover:-translate-y-1"
              >
                <div
                  className="w-20 h-20 rounded-full flex items-center justify-center mb-4 border-2 border-[var(--color-border)]"
                  style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
                >
                  <User size={36} />
                </div>
                <h4 className="text-lg font-bold" style={{ color: "var(--color-text)" }}>
                  {member.name}
                </h4>
                <span
                  className="text-xs font-semibold uppercase tracking-wider mt-1 mb-3"
                  style={{ color: "var(--color-accent)" }}
                >
                  {member.role}
                </span>
                <p className="text-sm leading-relaxed" style={{ color: "var(--color-text-secondary)" }}>
                  {member.desc}
                </p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* 5. CTA SECTION */}
      <section
        className="w-full section-padding text-center px-4"
        style={{
          background: "var(--brand-espresso)",
          color: "var(--brand-cream)",
        }}
      >
        <div className="max-w-2xl mx-auto flex flex-col items-center">
          <h2 className="text-2xl sm:text-3xl md:text-4xl font-bold mb-4 text-white">
            Join Us at Special Delicacies
          </h2>
          <p className="text-base md:text-lg mb-8 text-[#f5eee5]/80">
            Discover your next favorite meal or connect with our friendly team.
          </p>
          <div className="flex flex-wrap items-center justify-center gap-4">
            <Link to="/menu" className="btn-accent min-h-[44px]">
              Explore Our Menu <ArrowRight size={18} />
            </Link>
            <Link
              to="/contact"
              className="inline-flex items-center gap-2 px-6 py-3 rounded-full font-semibold text-white border-2 border-white/70 hover:bg-white/10 hover:border-white transition-all min-h-[44px]"
            >
              Contact Us
            </Link>
          </div>
        </div>
      </section>
    </div>
  );
}
