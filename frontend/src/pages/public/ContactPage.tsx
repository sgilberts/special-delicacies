import { useState, useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import axios from "axios";
import { MapPin, Phone, Mail, Clock, CheckCircle2, AlertTriangle, Send } from "lucide-react";
import api from "@/lib/api";
import SectionHeading from "@/components/public/SectionHeading";
import { useRestaurantContent } from "@/hooks/useRestaurantContent";

const contactSchema = z.object({
  name: z.string().min(1, "Name is required").max(150),
  email: z.string().email("Please enter a valid email address").max(200),
  phone: z.string().max(30).optional().or(z.literal("")),
  subject: z.string().min(1, "Subject is required").max(200),
  message: z.string().min(10, "Message must be at least 10 characters").max(3000),
});

type ContactFormValues = z.infer<typeof contactSchema>;

export default function ContactPage() {
  const { content } = useRestaurantContent();
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isSuccess, setIsSuccess] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  useEffect(() => {
    document.title = "Contact Us | Special Delicacies";
  }, []);

  const form = useForm<ContactFormValues>({
    resolver: zodResolver(contactSchema),
    defaultValues: {
      name: "",
      email: "",
      phone: "",
      subject: "",
      message: "",
    },
  });

  const onSubmit = async (values: ContactFormValues) => {
    setIsSubmitting(true);
    setSubmitError(null);
    try {
      await api.post("/contact", values);
      setIsSuccess(true);
      form.reset();
    } catch (err) {
      if (axios.isAxiosError(err) && err.response?.status === 422) {
        const apiErrors = (err.response.data?.errors || {}) as Record<string, string[]>;
        Object.entries(apiErrors).forEach(([field, messages]) => {
          if (messages && messages.length > 0) {
            form.setError(field as keyof ContactFormValues, { message: messages[0] });
          }
        });
      } else {
        setSubmitError("Unable to send your message. Please try again later.");
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="flex flex-col w-full min-h-screen">
      {/* 1. PAGE HEADER */}
      <section className="section-padding-sm bg-[var(--color-surface)] border-b border-[var(--color-border)]">
        <div className="section-container text-center">
          <SectionHeading
            eyebrow="CONNECT WITH US"
            title="Contact Us"
            subtitle="Have a question, feedback, or special reservation request? We would love to hear from you."
            centered
          />
          <div
            className="w-20 h-1 mx-auto mt-5 rounded-full"
            style={{ background: "var(--color-accent)" }}
          />
        </div>
      </section>

      {/* 2. MAIN CONTENT (TWO-COLUMN DESKTOP, STACKED MOBILE) */}
      <section className="section-padding bg-[var(--color-background)]">
        <div className="section-container">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12 items-start">
            {/* LEFT COLUMN: CONTACT FORM */}
            <div className="lg:col-span-7 pub-card p-6 md:p-8">
              {isSuccess ? (
                <div className="py-12 flex flex-col items-center text-center">
                  <div
                    className="w-16 h-16 rounded-full flex items-center justify-center mb-4"
                    style={{ background: "var(--color-surface-secondary)", color: "var(--color-success)" }}
                  >
                    <CheckCircle2 size={36} />
                  </div>
                  <h3 className="text-2xl font-bold mb-2" style={{ color: "var(--color-text)" }}>
                    Message Received!
                  </h3>
                  <p
                    className="text-base max-w-md mb-8 leading-relaxed"
                    style={{ color: "var(--color-text-secondary)" }}
                  >
                    Thank you for reaching out to Special Delicacies. Our team will review your inquiry and get back to you shortly.
                  </p>
                  <button
                    type="button"
                    onClick={() => setIsSuccess(false)}
                    className="btn-accent min-h-[44px]"
                  >
                    Send Another Message
                  </button>
                </div>
              ) : (
                <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-5" noValidate>
                  <div className="mb-2">
                    <h3 className="text-xl font-bold" style={{ color: "var(--color-text)" }}>
                      Send Us a Message
                    </h3>
                    <p className="text-sm mt-1" style={{ color: "var(--color-text-secondary)" }}>
                      Fill out the form below and we will respond as soon as possible.
                    </p>
                  </div>

                  {submitError && (
                    <div
                      className="p-4 rounded-xl flex items-center gap-3 border text-sm"
                      style={{
                        background: "var(--color-surface-secondary)",
                        borderColor: "var(--color-danger)",
                        color: "var(--color-danger)",
                      }}
                    >
                      <AlertTriangle size={20} className="shrink-0" />
                      <span>{submitError}</span>
                    </div>
                  )}

                  {/* Name */}
                  <div>
                    <label htmlFor="name" className="pub-label">
                      Full Name <span style={{ color: "var(--color-danger)" }}>*</span>
                    </label>
                    <input
                      id="name"
                      type="text"
                      placeholder="e.g. Ama Mensah"
                      className={`pub-input ${form.formState.errors.name ? "pub-input--error" : ""}`}
                      {...form.register("name")}
                      disabled={isSubmitting}
                    />
                    {form.formState.errors.name && (
                      <p className="pub-field-error">{form.formState.errors.name.message}</p>
                    )}
                  </div>

                  {/* Email & Phone Grid */}
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                      <label htmlFor="email" className="pub-label">
                        Email Address <span style={{ color: "var(--color-danger)" }}>*</span>
                      </label>
                      <input
                        id="email"
                        type="email"
                        placeholder="you@example.com"
                        className={`pub-input ${form.formState.errors.email ? "pub-input--error" : ""}`}
                        {...form.register("email")}
                        disabled={isSubmitting}
                      />
                      {form.formState.errors.email && (
                        <p className="pub-field-error">{form.formState.errors.email.message}</p>
                      )}
                    </div>

                    <div>
                      <label htmlFor="phone" className="pub-label">
                        Phone Number <span className="text-xs font-normal text-[var(--color-text-secondary)]">(Optional)</span>
                      </label>
                      <input
                        id="phone"
                        type="tel"
                        placeholder="e.g. +233 24 000 0000"
                        className={`pub-input ${form.formState.errors.phone ? "pub-input--error" : ""}`}
                        {...form.register("phone")}
                        disabled={isSubmitting}
                      />
                      {form.formState.errors.phone && (
                        <p className="pub-field-error">{form.formState.errors.phone.message}</p>
                      )}
                    </div>
                  </div>

                  {/* Subject */}
                  <div>
                    <label htmlFor="subject" className="pub-label">
                      Subject <span style={{ color: "var(--color-danger)" }}>*</span>
                    </label>
                    <input
                      id="subject"
                      type="text"
                      placeholder="e.g. Table Reservation / General Inquiry"
                      className={`pub-input ${form.formState.errors.subject ? "pub-input--error" : ""}`}
                      {...form.register("subject")}
                      disabled={isSubmitting}
                    />
                    {form.formState.errors.subject && (
                      <p className="pub-field-error">{form.formState.errors.subject.message}</p>
                    )}
                  </div>

                  {/* Message */}
                  <div>
                    <label htmlFor="message" className="pub-label">
                      Message <span style={{ color: "var(--color-danger)" }}>*</span>
                    </label>
                    <textarea
                      id="message"
                      rows={5}
                      placeholder="Write your message or inquiry here..."
                      className={`pub-input resize-y ${form.formState.errors.message ? "pub-input--error" : ""}`}
                      {...form.register("message")}
                      disabled={isSubmitting}
                    />
                    {form.formState.errors.message && (
                      <p className="pub-field-error">{form.formState.errors.message.message}</p>
                    )}
                  </div>

                  {/* Submit Button */}
                  <div className="pt-2">
                    <button
                      type="submit"
                      disabled={isSubmitting}
                      className="btn-accent w-full sm:w-auto min-h-[44px] justify-center"
                    >
                      {isSubmitting ? (
                        <>Sending...</>
                      ) : (
                        <>
                          Send Message <Send size={16} />
                        </>
                      )}
                    </button>
                  </div>
                </form>
              )}
            </div>

            {/* RIGHT COLUMN: CONTACT INFO */}
            <div className="lg:col-span-5 space-y-6">
              <div>
                <h3 className="text-2xl font-bold mb-2" style={{ color: "var(--color-text)" }}>
                  Get in Touch
                </h3>
                <p className="text-sm leading-relaxed" style={{ color: "var(--color-text-secondary)" }}>
                  Reach out directly via phone or email, or drop by our location. We are delighted to assist you.
                </p>
              </div>

              {/* Info Cards */}
              <div className="space-y-4">
                <div className="pub-card p-5 flex items-start gap-4">
                  <div
                    className="w-11 h-11 rounded-full flex items-center justify-center shrink-0"
                    style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
                  >
                    <MapPin size={22} />
                  </div>
                  <div>
                    <h4 className="text-sm font-bold uppercase tracking-wider mb-1" style={{ color: "var(--color-text)" }}>
                      Location
                    </h4>
                    <p className="text-sm font-medium" style={{ color: "var(--color-text)" }}>
                      {content.restaurant_address || "100 Gourmet Boulevard, Culinary Quarter"}
                    </p>
                    <span className="text-xs text-[var(--color-text-secondary)] opacity-80">
                      Accra, Ghana
                    </span>
                  </div>
                </div>

                <div className="pub-card p-5 flex items-start gap-4">
                  <div
                    className="w-11 h-11 rounded-full flex items-center justify-center shrink-0"
                    style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
                  >
                    <Phone size={22} />
                  </div>
                  <div>
                    <h4 className="text-sm font-bold uppercase tracking-wider mb-1" style={{ color: "var(--color-text)" }}>
                      Phone
                    </h4>
                    <p className="text-sm font-medium" style={{ color: "var(--color-text)" }}>
                      {content.restaurant_phone || "+233 30 123 4567"}
                    </p>
                    <span className="text-xs text-[var(--color-text-secondary)]">
                      Available during operating hours
                    </span>
                  </div>
                </div>

                <div className="pub-card p-5 flex items-start gap-4">
                  <div
                    className="w-11 h-11 rounded-full flex items-center justify-center shrink-0"
                    style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
                  >
                    <Mail size={22} />
                  </div>
                  <div>
                    <h4 className="text-sm font-bold uppercase tracking-wider mb-1" style={{ color: "var(--color-text)" }}>
                      Email
                    </h4>
                    <p className="text-sm font-medium" style={{ color: "var(--color-text)" }}>
                      {content.restaurant_email || "contact@special-delicacies.local"}
                    </p>
                    <span className="text-xs text-[var(--color-text-secondary)]">
                      Inquiries answered within 24 hours
                    </span>
                  </div>
                </div>

                {/* Opening Hours */}
                <div className="pub-card p-5">
                  <div className="flex items-center gap-3 mb-4">
                    <div
                      className="w-10 h-10 rounded-full flex items-center justify-center shrink-0"
                      style={{ background: "var(--color-surface-secondary)", color: "var(--color-accent)" }}
                    >
                      <Clock size={20} />
                    </div>
                    <h4 className="text-base font-bold" style={{ color: "var(--color-text)" }}>
                      Opening Hours
                    </h4>
                  </div>
                  <div className="space-y-2 text-sm divide-y divide-[var(--color-border)]">
                    <div className="flex justify-between py-1.5" style={{ color: "var(--color-text)" }}>
                      <span className="font-medium">Restaurant Hours</span>
                      <span style={{ color: "var(--color-text-secondary)" }}>
                        {content.opening_hours_days || content.operating_hours || "Monday – Sunday: 11:00 AM – 10:00 PM"}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
