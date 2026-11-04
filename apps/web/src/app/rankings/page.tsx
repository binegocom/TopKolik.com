"use client";

import { useState } from "react";
import { Trophy, Shield, Info, Medal } from "lucide-react";

export default function RankingsPage() {
  const [tab, setTab] = useState<"analysts" | "clubs">("analysts");

  const analysts = [
    { rank: 1, name: "Kerem Taktik", predictions: 15, accuracy: 80.0, exact: 4, pts: 48, badges: ["Skor Avcısı", "Usta Analist"] },
    { rank: 2, name: "Selin Asist", predictions: 14, accuracy: 78.5, exact: 3, pts: 42, badges: ["Usta Analist"] },
    { rank: 3, name: "Burak Pres", predictions: 15, accuracy: 66.7, exact: 2, pts: 37, badges: ["Maç Günü Müdavimi"] },
    { rank: 4, name: "Mehmet OrtaSaha", predictions: 13, accuracy: 61.5, exact: 2, pts: 31, badges: [] },
    { rank: 5, name: "Can Forvet", predictions: 12, accuracy: 58.3, exact: 1, pts: 28, badges: [] },
  ];

  const clubs = [
    { rank: 1, name: "Galatasaray", supporters: 128, predictions: 420, avgPts: 5.12, qualified: true },
    { rank: 2, name: "Fenerbahçe", supporters: 115, predictions: 390, avgPts: 4.97, qualified: true },
    { rank: 3, name: "Beşiktaş", supporters: 94, predictions: 310, avgPts: 4.81, qualified: true },
    { rank: 4, name: "Trabzonspor", supporters: 68, predictions: 210, avgPts: 4.67, qualified: true },
    { rank: 5, name: "Samsunspor", supporters: 4, predictions: 12, avgPts: 4.25, qualified: false },
  ];

  return (
    <div className="max-w-4xl mx-auto space-y-6">
      {/* Title */}
      <div className="border-b border-graphite-border pb-4">
        <h1 className="text-2xl font-black text-white tracking-tight">Taraftar ve Analist Ligi</h1>
        <p className="text-sm text-[#9EBAAF] mt-1">
          Haftalık maç tahminleri ile taraftar puanlarını topla, takımını ve kendini zirveye taşı.
        </p>
      </div>

      {/* Tabs */}
      <div className="flex rounded-xl bg-graphite-surface p-1 border border-graphite-border max-w-md">
        <button
          onClick={() => setTab("analysts")}
          className={`flex-1 py-2 text-xs font-bold rounded-lg flex items-center justify-center space-x-2 transition-all ${
            tab === "analysts" ? "bg-graphite-card text-electric shadow" : "text-[#9EBAAF] hover:text-white"
          }`}
        >
          <Trophy className="w-4 h-4" />
          <span>En İyi Analistler</span>
        </button>
        <button
          onClick={() => setTab("clubs")}
          className={`flex-1 py-2 text-xs font-bold rounded-lg flex items-center justify-center space-x-2 transition-all ${
            tab === "clubs" ? "bg-graphite-card text-electric shadow" : "text-[#9EBAAF] hover:text-white"
          }`}
        >
          <Shield className="w-4 h-4" />
          <span>Kulüp Sıralaması</span>
        </button>
      </div>

      {/* Banner for Clubs Tab */}
      {tab === "clubs" && (
        <div className="bg-graphite-card border border-pitch rounded-xl p-4 flex items-center space-x-3 text-xs text-[#9EBAAF]">
          <Info className="w-4 h-4 text-electric shrink-0" />
          <span>
            Kulüp sıralamasında yer almak için en az 5 aktif tahminci taraftar gereklidir. Sıralama tahmin başına ortalama puanla belirlenir.
          </span>
        </div>
      )}

      {/* Table */}
      <div className="bg-graphite-card border border-graphite-border rounded-2xl overflow-hidden shadow-xl">
        {tab === "analysts" ? (
          <div className="divide-y divide-graphite-border">
            {analysts.map((a) => (
              <div key={a.rank} className="p-4 flex items-center justify-between hover:bg-graphite-surface/50 transition-colors">
                <div className="flex items-center space-x-4">
                  <div
                    className={`w-8 h-8 rounded-full flex items-center justify-center font-black text-xs ${
                      a.rank <= 3 ? "bg-electric/20 text-electric border border-electric/40" : "bg-graphite-surface text-[#9EBAAF]"
                    }`}
                  >
                    {a.rank}
                  </div>
                  <div>
                    <div className="text-sm font-bold text-white">{a.name}</div>
                    <div className="text-xs text-[#5E796F]">
                      {a.predictions} Tahmin • %{a.accuracy} Başarı • {a.exact} Tam İsabet
                    </div>
                    {a.badges.length > 0 && (
                      <div className="flex gap-1.5 mt-1.5">
                        {a.badges.map((b) => (
                          <span key={b} className="text-[10px] font-bold px-2 py-0.5 rounded bg-pitch/30 text-electric">
                            {b}
                          </span>
                        ))}
                      </div>
                    )}
                  </div>
                </div>

                <div className="text-right">
                  <div className="text-lg font-black text-electric">{a.pts}</div>
                  <div className="text-[10px] text-[#5E796F]">Puan</div>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <div className="divide-y divide-graphite-border">
            {clubs.map((c) => (
              <div key={c.rank} className="p-4 flex items-center justify-between hover:bg-graphite-surface/50 transition-colors">
                <div className="flex items-center space-x-4">
                  <div
                    className={`w-8 h-8 rounded-full flex items-center justify-center font-black text-xs ${
                      c.qualified ? "bg-pitch/40 text-electric border border-pitch" : "bg-graphite-surface text-gray-500"
                    }`}
                  >
                    {c.qualified ? c.rank : "-"}
                  </div>
                  <div>
                    <div className="flex items-center space-x-2">
                      <span className="text-sm font-bold text-white">{c.name}</span>
                      {!c.qualified && (
                        <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-gray-800 text-gray-400">
                          Aday (Yetersiz Sayı)
                        </span>
                      )}
                    </div>
                    <div className="text-xs text-[#5E796F]">
                      {c.supporters} Taraftar • {c.predictions} Toplam Tahmin
                    </div>
                  </div>
                </div>

                <div className="text-right">
                  <div className="text-lg font-black text-electric">{c.avgPts.toFixed(2)}</div>
                  <div className="text-[10px] text-[#5E796F]">Ort. Puan</div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
