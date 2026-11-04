"use client";

import { useState } from "react";
import Link from "next/link";
import { CheckCircle2, ShieldCheck, Trophy } from "lucide-react";

export default function PredictionsPage() {
  const [outcome, setOutcome] = useState<"home" | "draw" | "away">("home");
  const [homeScore, setHomeScore] = useState(2);
  const [awayScore, setAwayScore] = useState(1);
  const [goalRange, setGoalRange] = useState("2_3");
  const [firstScorer, setFirstScorer] = useState("home");
  const [saved, setSaved] = useState(false);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSaved(true);
  };

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      {/* Title */}
      <div className="border-b border-graphite-border pb-4">
        <h1 className="text-2xl font-black text-white tracking-tight">Skor Tahmin Merkezi</h1>
        <p className="text-sm text-[#9EBAAF] mt-1">
          Tamamen ücretsiz beceri ve tahmin ligi. Bahis veya para ödülü içermez.
        </p>
      </div>

      {/* Match Card */}
      <div className="bg-graphite-card border border-graphite-border rounded-2xl p-6 space-y-6">
        <div className="text-center space-y-1">
          <span className="text-xs font-bold text-electric uppercase tracking-wider">Süper Lig • 11. Hafta</span>
          <h2 className="text-xl font-black text-white">Galatasaray vs Fenerbahçe</h2>
          <p className="text-xs text-[#5E796F]">Başlama: 20:00 • RAMS Park</p>
        </div>

        <form onSubmit={handleSubmit} className="space-y-6">
          {/* Exact Score Picker */}
          <div>
            <label className="block text-xs font-bold text-[#9EBAAF] uppercase tracking-wider mb-2">
              Skor Tahmini
            </label>
            <div className="bg-graphite-surface border border-graphite-border rounded-xl p-4 flex items-center justify-around">
              <div className="text-center">
                <div className="text-sm font-bold text-white mb-2">Galatasaray</div>
                <div className="flex items-center space-x-3">
                  <button
                    type="button"
                    onClick={() => setHomeScore((s) => Math.max(0, s - 1))}
                    className="w-8 h-8 rounded-lg bg-graphite-card border border-graphite-border text-white font-bold hover:border-electric"
                  >
                    -
                  </button>
                  <span className="text-3xl font-black text-white w-8">{homeScore}</span>
                  <button
                    type="button"
                    onClick={() => setHomeScore((s) => Math.min(9, s + 1))}
                    className="w-8 h-8 rounded-lg bg-graphite-card border border-graphite-border text-white font-bold hover:border-electric"
                  >
                    +
                  </button>
                </div>
              </div>

              <span className="text-3xl font-black text-[#5E796F]">:</span>

              <div className="text-center">
                <div className="text-sm font-bold text-white mb-2">Fenerbahçe</div>
                <div className="flex items-center space-x-3">
                  <button
                    type="button"
                    onClick={() => setAwayScore((s) => Math.max(0, s - 1))}
                    className="w-8 h-8 rounded-lg bg-graphite-card border border-graphite-border text-white font-bold hover:border-electric"
                  >
                    -
                  </button>
                  <span className="text-3xl font-black text-white w-8">{awayScore}</span>
                  <button
                    type="button"
                    onClick={() => setAwayScore((s) => Math.min(9, s + 1))}
                    className="w-8 h-8 rounded-lg bg-graphite-card border border-graphite-border text-white font-bold hover:border-electric"
                  >
                    +
                  </button>
                </div>
              </div>
            </div>
          </div>

          {/* 1-X-2 */}
          <div>
            <label className="block text-xs font-bold text-[#9EBAAF] uppercase tracking-wider mb-2">
              Maç Sonucu (1-X-2)
            </label>
            <div className="grid grid-cols-3 gap-3">
              {[
                { key: "home", label: "1 (Galatasaray)" },
                { key: "draw", label: "X (Beraberlik)" },
                { key: "away", label: "2 (Fenerbahçe)" },
              ].map((opt) => (
                <button
                  key={opt.key}
                  type="button"
                  onClick={() => setOutcome(opt.key as any)}
                  className={`py-3 px-2 rounded-xl text-xs sm:text-sm font-bold border transition-all ${
                    outcome === opt.key
                      ? "bg-pitch text-white border-electric"
                      : "bg-graphite-surface text-[#9EBAAF] border-graphite-border hover:border-electric/50"
                  }`}
                >
                  {opt.label}
                </button>
              ))}
            </div>
          </div>

          {/* Goal Range & First Scorer */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-bold text-[#9EBAAF] uppercase tracking-wider mb-2">
                Toplam Gol Aralığı
              </label>
              <div className="grid grid-cols-3 gap-2">
                {[
                  { key: "0_1", label: "0-1 Gol" },
                  { key: "2_3", label: "2-3 Gol" },
                  { key: "4_plus", label: "4+ Gol" },
                ].map((g) => (
                  <button
                    key={g.key}
                    type="button"
                    onClick={() => setGoalRange(g.key)}
                    className={`py-2 text-xs font-bold rounded-lg border transition-all ${
                      goalRange === g.key
                        ? "bg-electric/20 border-electric text-electric"
                        : "bg-graphite-surface border-graphite-border text-[#9EBAAF]"
                    }`}
                  >
                    {g.label}
                  </button>
                ))}
              </div>
            </div>

            <div>
              <label className="block text-xs font-bold text-[#9EBAAF] uppercase tracking-wider mb-2">
                İlk Golü Atan Takım
              </label>
              <div className="grid grid-cols-3 gap-2">
                {[
                  { key: "home", label: "Ev Sahibi" },
                  { key: "away", label: "Deplasman" },
                  { key: "none", label: "Gol Olmaz" },
                ].map((s) => (
                  <button
                    key={s.key}
                    type="button"
                    onClick={() => setFirstScorer(s.key)}
                    className={`py-2 text-xs font-bold rounded-lg border transition-all ${
                      firstScorer === s.key
                        ? "bg-electric/20 border-electric text-electric"
                        : "bg-graphite-surface border-graphite-border text-[#9EBAAF]"
                    }`}
                  >
                    {s.label}
                  </button>
                ))}
              </div>
            </div>
          </div>

          <button
            type="submit"
            className="w-full py-3.5 rounded-xl bg-electric hover:bg-electric-hover text-[#072B1A] font-black text-sm tracking-wide transition-colors shadow-lg shadow-electric/20"
          >
            {saved ? "Tahmin Kaydedildi (Güncelle)" : "Tahmini Kaydet"}
          </button>
        </form>

        {saved && (
          <div className="flex items-center space-x-2 text-electric text-sm font-semibold justify-center pt-2">
            <CheckCircle2 className="w-4 h-4" />
            <span>Tahmininiz başarıyla sisteme işlendi!</span>
          </div>
        )}
      </div>

      {/* Rules Card */}
      <div className="bg-graphite-card border border-graphite-border rounded-xl p-5 space-y-3">
        <div className="flex items-center space-x-2 text-electric font-bold text-sm">
          <ShieldCheck className="w-5 h-5" />
          <span>Şeffaf Puanlama Kuralları</span>
        </div>
        <ul className="text-xs text-[#9EBAAF] space-y-1.5 list-disc list-inside">
          <li>Doğru Maç Sonucu (1-X-2): 3 Puan</li>
          <li>Tam Skor İsabeti: 5 Puan</li>
          <li>Toplam Gol Aralığı: 2 Puan</li>
          <li>İlk Golü Atan Takım: 2 Puan</li>
        </ul>
        <p className="text-[11px] text-[#5E796F] italic">
          Tahminler başlama vuruşunda kilitlenir. Hakem raporları ve VAR kararları kesinleştikten sonra puanlar hesaplanır.
        </p>
      </div>
    </div>
  );
}
